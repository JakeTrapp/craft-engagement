<?php

/**
 * Drop and recreate the disposable `db_test` database inside DDEV.
 *
 * Refuses to run unless the DDEV project, database name and identity marker all match,
 * so it can never wipe the manual site's `db` database or any other database.
 */

declare(strict_types=1);

$project = getenv('DDEV_PROJECT') ?: '';
$host = getenv('CRAFT_DB_SERVER') ?: 'db';
$database = getenv('CRAFT_TEST_DB_DATABASE') ?: 'db_test';
$identity = getenv('ENGAGEMENT_TEST_DB_IDENTITY') ?: '';
$manualDatabase = getenv('CRAFT_DB_DATABASE') ?: 'db';
$expectedProject = 'craft-engagement-testbench';

if ($project !== $expectedProject || $host !== 'db' || !preg_match('/^[a-zA-Z0-9_]+_test$/', $database) || $identity !== $project . ':' . $database) {
    throw new RuntimeException('Refusing to reset a database without the matching DDEV testbench identity.');
}
if ($manualDatabase !== 'db' || $database === $manualDatabase) {
    throw new RuntimeException('Refusing to recreate the test database unless the manual site remains on the separate "db" database.');
}

$admin = new PDO("mysql:host=$host;port=3306;dbname=db", 'root', 'root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$exists = $admin->prepare('SELECT 1 FROM information_schema.schemata WHERE schema_name = :database');
$exists->execute(['database' => $database]);

if ($exists->fetchColumn()) {
    $existing = new PDO("mysql:host=$host;port=3306;dbname=$database", 'root', 'root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    try {
        $marker = $existing->query('SELECT `identity` FROM engagement_test_database_marker LIMIT 1')->fetchColumn();
    } catch (Throwable $e) {
        throw new RuntimeException('Refusing to reset an unmarked database. Run testbench/bin/mark-test-database once after confirming it is the disposable test database.', 0, $e);
    }
    if ($marker !== $identity) {
        throw new RuntimeException('Refusing to reset a database with a mismatched identity marker.');
    }
}

$admin->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $database));
$admin->exec(sprintf('CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $database));
$admin->exec(sprintf("GRANT ALL PRIVILEGES ON `%s`.* TO 'db'@'%%'", $database));
$admin->exec('FLUSH PRIVILEGES');

$test = new PDO("mysql:host=$host;port=3306;dbname=$database", 'db', 'db', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$test->exec('CREATE TABLE engagement_test_database_marker (`identity` VARCHAR(255) NOT NULL PRIMARY KEY)');
$test->prepare('INSERT INTO engagement_test_database_marker (`identity`) VALUES (:identity)')->execute(['identity' => $identity]);

fwrite(STDOUT, "Fresh test database created: $database\n");
