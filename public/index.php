<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;
use Slim\Middleware\MethodOverrideMiddleware;
use GuzzleHttp\Client;

session_start();

$container = new Container();
$container->set('renderer', function () {
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});

$container -> set('flash', function () {
    return new \Slim\Flash\Messages();
});

$app = AppFactory::createFromContainer($container);
$app->addErrorMiddleware(true, true, true);
$app->add(MethodOverrideMiddleware::class);

$router = $app->getRouteCollector()->getRouteParser();

$app->get('/', function ($req, $resp) {
    $params = [];
    $messages = $this -> get('flash') -> getMessages();
    if (!empty($messages)) {
        $params['messages'] = $messages;
    }
    return $this -> get('renderer') -> render($resp, 'main.phtml', $params);
}) -> setName('main');

$app->get('/urls', function ($req, $resp) use ($router) {
    $connection = getConnectionToDB();
    if ($connection === false) {
        return redirectToMainWithInternalError($this, $resp, $router);
    }

    $queryForUrls = "SELECT urls.id AS urls_id, name, MAX(url_checks.created_at) as last_check_time 
                            FROM urls LEFT JOIN url_checks ON urls.id = url_checks.url_id 
                            GROUP BY urls_id 
                            ORDER BY urls_id DESC";
    $resQueryForUrls = $connection -> query($queryForUrls);
    $urls = [];
    foreach ($resQueryForUrls as $row) {
        $urls[] = ['id' => $row['urls_id'], 'name' => $row['name'], 'lastCheckTime' => $row['last_check_time']];
    }

    $params = ['urls' => $urls];

    $messages = $this -> get('flash') -> getMessages();
    if (!empty($messages)) {
        $params['messages'] = $messages;
    }

    return $this -> get('renderer') -> render($resp, 'urls.phtml', $params);
}) -> setName('urls');

$app->get('/urls/{id}', function ($req, $resp, $args) use ($router) {
    $connection = getConnectionToDB();
    if ($connection === false) {
        return redirectToMainWithInternalError($this, $resp, $router);
    }

    $id = $args['id'];

    $queryForUrl = "SELECT * FROM urls WHERE id={$id}";
    $resQueryForUrl = $connection -> query($queryForUrl);
    $urlData = $resQueryForUrl -> fetch();
    $params = ['url' => ['id' => $id, 'name' => $urlData['name'], 'created_at' => $urlData['created_at']]];

    $queryForUrlChecks = "SELECT * FROM url_checks WHERE url_id={$id} ORDER BY id DESC";
    $resQueryForUrlChecks = $connection -> query($queryForUrlChecks);
    $urlChecks = [];
    foreach ($resQueryForUrlChecks as $row) {
        $urlChecks[] = ['id' => $row['id'], 'created_at' => $row['created_at']];
    }
    $params['urlChecks'] = $urlChecks;

    $messages = $this -> get('flash') -> getMessages();
    if (!empty($messages)) {
        $params['messages'] = $messages;
    }

    return $this -> get('renderer') -> render($resp, 'url.phtml', $params);
}) -> setName('urlID');

$app -> post('/clearurls', function ($req, $resp) use ($router) {
    $connection = getConnectionToDB();
    if ($connection === false) {
        return redirectToMainWithInternalError($this, $resp, $router);
    }

    $queryForClearing = "TRUNCATE urls, url_checks";
    $connection->query($queryForClearing);
    $this -> get('flash') -> addMessage('warning', 'Таблицы очищены');
    return $resp -> withRedirect($router -> urlFor('main'), 302);
});

$app -> post('/urls', function ($req, $resp) use ($router) {
    $inputedURL = $req -> getParsedBodyParam('url', null);

    if ($inputedURL === null) {
        $this -> get('flash') -> addMessage('warning', 'Нужно вести адрес веб-страницы');
        return $resp -> withRedirect($router -> urlFor('main'));
    }

    $url = $inputedURL['name'];
    $urlParsed = parse_url($url);
    $scheme = $urlParsed['scheme'];
    $host = $urlParsed['host'];
    $validator = new Valitron\Validator(array('url' => $url, 'host' => $host));
    $validator -> rule('required', 'url')
        -> rule('lengthMax', 'url', 255)
        -> rule('url', 'url')
        -> rule('contains', 'host', '.');

    if (!($validator->validate())) {
        return $this -> get('renderer')
            -> render($resp, 'main.phtml', ['badURL' => true, 'inputedURL' => $inputedURL['name']]);
    }

    $connection = getConnectionToDB();
    if ($connection === false) {
        return redirectToMainWithInternalError($this, $resp, $router);
    }

    $queryForExisting = "SELECT COUNT(*) AS counts FROM urls WHERE name='{$scheme}://{$host}'";
    $resOfQueryForExisting = $connection->query($queryForExisting);
    if (($resOfQueryForExisting -> fetch())['counts'] === 0) {
        $queryForInsertNewData = "INSERT INTO urls (name, created_at) 
                                VALUES ('{$scheme}://{$host}', date_trunc('second', current_timestamp))";
        $connection->query($queryForInsertNewData);
        $this -> get('flash') -> addMessage('success', 'Страница успешно добавлена');
    } else {
        $this -> get('flash') -> addMessage('warning', 'Страница уже существует');
    }
    return $resp -> withRedirect($router
        -> urlFor('main'), 302);
});

$app->post('/urls/{id}/checks', function ($req, $resp, $args) use ($router) {
    $id = $args['id'];

    $connection = getConnectionToDB();
    if ($connection === false) {
        return redirectToMainWithInternalError($this, $resp, $router);
    }

    $queryGetUrl = "SELECT name FROM urls WHERE id='{$id}'";
    $resQueryGetUrl = $connection->query($queryGetUrl);
    $fetchedRes = $resQueryGetUrl -> fetch();
    if ($fetchedRes === false) {
        $this -> get('flash') -> addMessage('error', 'При проверке возникла ошибка. Такой записи не существует.');
        return $resp -> withRedirect($router -> urlFor('urls'), 303);
    }

    $client = new Client([]);

    $queryForInsertNewCheck = "INSERT INTO url_checks (url_id, created_at) 
                            VALUES ('{$id}', date_trunc('second', current_timestamp))";
    $connection->query($queryForInsertNewCheck);
    $this -> get('flash') -> addMessage('success', 'Страница успешно проверена');
    return $resp -> withRedirect($router -> urlFor('urlID', ['id' => $id]), 302);
});

function redirectToMainWithInternalError($DIContainer, $resp, $router)
{
    $DIContainer -> get('flash')
        -> addMessage('error', 'Возникла внутренняя ошибка сервера. Попробуйте выполнить действие позже.');
    return $resp -> withRedirect($router -> urlFor('main'), 302);
}

function getConnectionToDB()
{
    $dbDriver = 'pgsql';
    $dbHost = 'localhost';
    $dbPort = '5432';
    $dbName = 'project9test';
    $dbUserName = 'Dragon';
    $dbUserPassword = 'qwe';
    $connectionString = "{$dbDriver}:host={$dbHost};port={$dbPort};dbname={$dbName};";try {
        return new PDO($connectionString, $dbUserName, $dbUserPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Exception) {
        return false;
    }
}

$app->run();
