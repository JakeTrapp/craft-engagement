<?php

use craft\helpers\App;

return [
    'dsn' => sprintf(
        'mysql:host=%s;port=%s;dbname=%s',
        App::env('CRAFT_DB_SERVER') ?: 'db',
        App::env('CRAFT_DB_PORT') ?: '3306',
        App::env('CRAFT_DB_DATABASE') ?: 'db',
    ),
    'user' => App::env('CRAFT_DB_USER') ?: 'db',
    'password' => App::env('CRAFT_DB_PASSWORD') ?: 'db',
    'charset' => 'utf8mb4',
    'tablePrefix' => App::env('CRAFT_DB_TABLE_PREFIX') ?: '',
];
