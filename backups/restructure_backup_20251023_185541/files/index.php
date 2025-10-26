<?php
[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/routes.php';
$app->run();
