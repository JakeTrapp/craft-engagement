<?php

namespace Helper;

/**
 * Refuses to run the suite outside the dedicated DDEV testbench and keeps db_test marked
 * (Craft's dbSetup `clean` drops every table, including the marker).
 */
class Integration extends \Codeception\Module
{
    public function _beforeSuite($settings = []): void
    {
        parent::_beforeSuite($settings);

        $project = getenv('DDEV_PROJECT') ?: '';
        $database = getenv('CRAFT_TEST_DB_DATABASE') ?: '';
        $identity = getenv('ENGAGEMENT_TEST_DB_IDENTITY') ?: '';
        $host = getenv('CRAFT_DB_SERVER') ?: '';
        if ($project !== 'craft-engagement-testbench' || $host !== 'db' || $database !== 'db_test' || $identity !== $project . ':' . $database) {
            throw new \RuntimeException('Refusing to run outside the dedicated Engagement integration environment.');
        }

        $pdo = new \PDO("mysql:host=$host;port=3306;dbname=$database", 'db', 'db', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE IF NOT EXISTS engagement_test_database_marker (`identity` VARCHAR(255) NOT NULL PRIMARY KEY)');
        $pdo->prepare('INSERT IGNORE INTO engagement_test_database_marker (`identity`) VALUES (:identity)')->execute(['identity' => $identity]);
    }
}
