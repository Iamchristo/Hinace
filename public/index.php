<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Http\Request;
use App\Core\Http\Router;

$envPath = __DIR__ . '/..';
if (is_file($envPath . '/.env')) {
    Dotenv\Dotenv::createImmutable($envPath)->load();
}

$router = new Router();
require __DIR__ . '/../app/routes.php';

$response = $router->dispatch(Request::fromGlobals());
$response->send();
