<?php

/**
 * Mark an existing, already-confirmed disposable db_test database so the guarded scripts accept it.
 */

declare(strict_types=1);

$project = getenv('DDEV_PROJECT') ?: '';
$database = getenv('CRAFT_TEST_DB_DATABASE') ?: '';
$identity = getenv('ENGAGEMENT_TEST_DB_IDENTITY') ?: '';

if ($project !== 'craft-engagement-testbench' || !preg_match('/^[a-zA-Z0-9_]+_test$/', $database) || $identity !== $project . ':' . $database) {
    throw new RuntimeException('Refusing to mark a database without the matching DDEV testbench identity.');
}

$pdo = new PDO("mysql:host=db;port=3306;dbname=$database", 'db', 'db', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE IF NOT EXISTS engagement_test_database_marker (`identity` VARCHAR(255) NOT NULL PRIMARY KEY)');
$current = $pdo->query('SELECT `identity` FROM engagement_test_database_marker LIMIT 1')->fetchColumn();
if ($current !== false && $current !== $identity) {
    throw new RuntimeException('The existing marker does not match this DDEV project.');
}
if ($current === false) {
    $pdo->prepare('INSERT INTO engagement_test_database_marker (`identity`) VALUES (:identity)')->execute(['identity' => $identity]);
}
fwrite(STDOUT, "Marked the disposable test database: $identity\n");
