<?php

use craft\helpers\App;

$database = App::env('CRAFT_TEST_DB_DATABASE') ?: '';
$project = getenv('DDEV_PROJECT') ?: '';
$identity = App::env('ENGAGEMENT_TEST_DB_IDENTITY') ?: '';

if (!preg_match('/^[a-zA-Z0-9_]+_test$/', $database) || $project !== 'craft-engagement-testbench' || $identity !== $project . ':' . $database) {
    throw new RuntimeException('Automated tests require the named DDEV test database and matching identity.');
}

return [
    'dsn' => sprintf('mysql:host=%s;port=%s;dbname=%s', App::env('CRAFT_DB_SERVER') ?: 'db', App::env('CRAFT_DB_PORT') ?: '3306', $database),
    'user' => App::env('CRAFT_DB_USER') ?: 'db',
    'password' => App::env('CRAFT_DB_PASSWORD') ?: 'db',
    'charset' => 'utf8mb4',
    'tablePrefix' => App::env('CRAFT_DB_TABLE_PREFIX') ?: '',
];
