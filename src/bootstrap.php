<?php
use Slim\Factory\AppFactory;
use Dotenv\Dotenv;
use Permits\Db;

require __DIR__ . '/../vendor/autoload.php';

$root = realpath(__DIR__ . '/..');
$dotenv = Dotenv::createImmutable($root);
$dotenv->safeLoad();

function envv($k, $d = null) { return $_ENV[$k] ?? $d; }

$dsn = str_replace('__ROOT__', $root, envv('DB_DSN', "sqlite:$root/data/app.sqlite"));
$db  = new Db($dsn, envv('DB_USER',''), envv('DB_PASS',''));

$app = AppFactory::create();
$app->addBodyParsingMiddleware();

return [$app, $db, $root];
