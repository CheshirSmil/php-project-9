<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;
use Slim\Middleware\MethodOverrideMiddleware;

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

    $queryForUrls = "SELECT id, name FROM urls ORDER BY id DESC";
    $resQueryForUrls = $connection -> query($queryForUrls);
    $urls = [];
    foreach ($resQueryForUrls as $row) {
        $urls[] = ['id' => $row['id'], 'name' => $row['name']];
    }
    $params = ['urls' => $urls];
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
    $dateTime = new DateTime($urlData['created_at']);
    $dateTimeFormatted = $dateTime->format('Y-m-d H:i:s');
    $params = ['url' => ['id' => $id, 'name' => $urlData['name'], 'created_at' => $dateTimeFormatted]];
    return $this -> get('renderer') -> render($resp, 'url.phtml', $params);
}) -> setName('urlID');

$app -> post('/clearurls', function ($req, $resp) use ($router) {
    $connection = getConnectionToDB();
    if ($connection === false) {
        return redirectToMainWithInternalError($this, $resp, $router);
    }

    $queryForClearing = "DELETE FROM urls";
    $connection->query($queryForClearing);
    $this -> get('flash') -> addMessage('warning', 'Таблица urls очищена');
    return $resp -> withRedirect($router -> urlFor('main'), 302);
});

$app -> post('/urls', function($req, $resp) use ($router) {
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
        return $this -> get('renderer') -> render($resp, 'main.phtml', ['badURL' => true, 'inputedURL' => $inputedURL['name']]);
    }

    $connection = getConnectionToDB();
    if ($connection === false) {
        return redirectToMainWithInternalError($this, $resp, $router);
    }

    $queryForExisting = "SELECT COUNT(*) AS counts FROM urls WHERE name='{$scheme}://{$host}'";
    $resOfQueryForExisting = $connection->query($queryForExisting);
    if (($resOfQueryForExisting -> fetch())['counts'] === 0) {
        $queryForInsertNewData = "INSERT INTO urls (name, created_at) VALUES ('{$scheme}://{$host}', current_timestamp)";
        $connection->query($queryForInsertNewData);
        $this -> get('flash') -> addMessage('success', 'Страница успешно добавлена');
    } else {
        $this -> get('flash') -> addMessage('warning', 'Страница уже существует');
    }
    return $resp -> withRedirect($router -> urlFor('main'), 302);

});

function redirectToMainWithInternalError($DIContainer, $resp, $router) {
    $DIContainer -> get('flash') -> addMessage('error', 'Возникла внутренняя ошибка сервера. Попробуйте выполнить действие позже.');
    return $resp -> withRedirect($router -> urlFor('main'), 302);
}

function getConnectionToDB() {
    $dbDriver = 'pgsql';
    $dbHost = 'localhost';
    $dbPort = '5432';
    $dbName = 'project9test';
    $dbUserName = 'Dragon';
    $dbUserPassword = 'qwe';
    $connectionString = "{$dbDriver}:host={$dbHost};port={$dbPort};dbname={$dbName};";
    try {
        return new PDO($connectionString, $dbUserName, $dbUserPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
    catch (Exception) {
        return false;
    }
}

$app->run();
