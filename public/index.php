<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Middleware\MethodOverrideMiddleware;
use DI\Container;
use Dotenv\Dotenv;
use Valitron\Validator;
use Carbon\Carbon;
use GuzzleHttp\Client;
use DiDom\Document;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Response;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;

session_start();

$container = new Container();
$container->set('view', function ($container) {
    $twig = Twig::create(__DIR__ . '/../templates');
    $twig->getEnvironment()->addGlobal('flash', $container->get('flash')->getMessages());
    return $twig;
});
$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});
$container->set('pdo', function () {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->safeLoad();

    $databaseUrl = parse_url($_ENV['DATABASE_URL']);
    if (!$databaseUrl) {
        throw new \Exception("Не удалось получить переменную среды DATABASE_URL");
    }
    $dbHost = $databaseUrl['host'] ?? '';
    $dbPort = $databaseUrl['port'] ?? '';
    $dbName = ltrim($databaseUrl['path'] ?? '', '/');
    $dbUser = $databaseUrl['user'] ?? '';
    $dbPassword = $databaseUrl['pass'] ?? '';

    $conStr = sprintf(
        "pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s",
        $dbHost,
        $dbPort,
        $dbName,
        $dbUser,
        $dbPassword
    );

    $pdo = new \PDO($conStr);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    return $pdo;
});

$container->set('client', function () {
    return new Client();
});

$app = AppFactory::createFromContainer($container);

$app->add(function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($container) {
    try {
        return $handler->handle($request);
    } catch (HttpNotFoundException) {
        $response = (new Response())->withStatus(404);
        return $container->get('view')->render($response, '404.twig.html');
    }
});

$app->addErrorMiddleware(true, true, true);
$app->add(MethodOverrideMiddleware::class);
$app->add(TwigMiddleware::createFromContainer($app));
$router = $app->getRouteCollector()->getRouteParser();

$app->get('/', function ($request, $response) {
    return $this->get('view')->render($response, "main.twig.html");
})->setName('main');

$app->get('/urls', function ($request, $response) {
    $pdo = $this->get('pdo');

    $selectedUrls = $pdo->query('SELECT id, name FROM urls ORDER BY created_at DESC')->fetchAll(\PDO::FETCH_UNIQUE);

    $queryChecks = 'SELECT 
    url_id, 
    created_at, 
    status_code, 
    h1, 
    title, 
    description 
    FROM url_checks';
    $stmt = $pdo->query($queryChecks);
    $selectedChecks = $stmt->fetchAll(\PDO::FETCH_UNIQUE);

    foreach ($selectedChecks as $key => $value) {
        if (array_key_exists($key, $selectedUrls)) {
            $selectedUrls[$key] = array_merge($selectedUrls[$key], $value);
        }
    }

    $params = [
        'selectedUrls' => $selectedUrls
    ];
    return $this->get('view')->render($response, 'urls/index.twig.html', $params);
})->setName('urls.index');

$app->get('/urls/{id:\d+}', function ($request, $response, $args) {
    $id = $args['id'];

        $pdo = $this->get('pdo');
        $query = 'SELECT * FROM urls WHERE id = ?';
        $stmt = $pdo->prepare($query);
        $stmt->execute([$id]);
        $selectedUrl = $stmt->fetch();

    if (empty($selectedUrl)) {
            return $this->get('view')->render($response, "404.twig.html");
    }

        $queryCheck = 'SELECT * FROM url_checks WHERE url_id = ? ORDER BY id DESC';
        $stmt = $pdo->prepare($queryCheck);
        $stmt->execute([$id]);
        $urlChecks = $stmt->fetchAll();

    if (is_null($urlChecks)) {
        throw new HttpNotFoundException($request);
    }

        $params = [
            'data' => $selectedUrl,
            'urlChecks' => $urlChecks,
        ];
        return $this->get('view')->render($response, 'urls/show.twig.html', $params);
})->setName('url.show');

$app->post('/urls', function ($request, $response) use ($router) {
    $formData = $request->getParsedBody()['url'];
    $validator = new Validator($formData);
    $validator->rule('required', 'name')->message('URL не должен быть пустым');
    $validator->rule('lengthMax', 'name', 255)->message('Некорректный URL');
    $validator->rule('url', 'name')->message('Некорректный URL');

    if (!$validator->validate()) {
        $errors = $validator->errors();
        $params = [
            'url' => $formData['name'],
            'errors' => $errors,
            'invalidForm' => 'is-invalid'
        ];
        return $this->get('view')->render($response->withStatus(422), 'main.twig.html', $params);
    }

    $pdo = $this->get('pdo');
    $url = mb_strtolower($formData['name']);
    $parsedUrl = parse_url($url);
    $urlName = "{$parsedUrl['scheme']}://{$parsedUrl['host']}";
    $createdAt = Carbon::now();

    $queryId = 'SELECT id FROM urls WHERE name = ?';
    $stmtForId = $pdo->prepare($queryId);
    $stmtForId->execute([$urlName]);
    $selectedId = (string) $stmtForId->fetchColumn();
    if ($selectedId) {
        $this->get('flash')->addMessage('success', 'Страница уже существует');
        return $response->withRedirect($router->urlFor('url.show', ['id' => $selectedId]));
    }

    $sql = "INSERT INTO urls (name, created_at) VALUES (?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$urlName, $createdAt]);
    $lastInsertId = (string) $pdo->lastInsertId();

    $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
    return $response->withRedirect($router->urlFor('url.show', ['id' => $lastInsertId]));
})->setName('urls.store');

$app->post('/urls/{url_id:\d+}/checks', function ($request, $response, $args) use ($router) {
    $id = $args['url_id'];

    $queryUrl = 'SELECT name FROM urls WHERE id = ?';
    $stmt = $this->get('pdo')->prepare($queryUrl);
    $stmt->execute([$id]);
    $selectedUrl = $stmt->fetch(\PDO::FETCH_COLUMN);

    $createdAt = Carbon::now();

    $client = $this->get('client');
    try {
            $res = $client->get($selectedUrl);
            $this->get('flash')->addMessage('success', 'Страница успешно проверена');
    } catch (RequestException $e) {
            $res = $e->getResponse();
            $this->get('flash')->clearMessages();
            $errorMessage = 'Проверка была выполнена успешно, но сервер ответил c ошибкой';
            $this->get('flash')->addMessage('error', $errorMessage);
    } catch (ConnectException $e) {
            $errorMessage = 'Произошла ошибка при проверке, не удалось подключиться';
            $this->get('flash')->addMessage('danger', $errorMessage);
            return $response->withRedirect($router->urlFor('url.show', ['id' => $id]));
    }

    $htmlBody = $res->getBody();
    /** @var Document $document */
    $document = new Document((string)$htmlBody);
    $statusCode = $res->getStatusCode();
    $h1 = optional($document->first('h1'))->text();
    $title = optional($document->first('title'))->text();
    $description = optional($document->first('meta[name="description"]'))
        ->getAttribute('content');

    $sql = "INSERT INTO url_checks (
        url_id, 
        created_at, 
        status_code, 
        h1, 
        title, 
        description) 
        VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $this->get('pdo')->prepare($sql);
    $stmt->execute([$id, $createdAt, $statusCode, $h1, $title, $description]);
    return $response->withRedirect($router->urlFor('url.show', ['id' => $id]));
})->setName('urls.checks.store');

$app->run();
