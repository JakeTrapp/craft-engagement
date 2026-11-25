<?php

declare(strict_types=1);

namespace Tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\db\Query;
use jtdev\craftengagement\fields\FavoritesField;
use jtdev\craftengagement\fields\LikesField;
use jtdev\craftengagement\fields\RatingField;
use jtdev\craftengagement\helpers\SecurityHelper;
use jtdev\craftengagement\migrations\m261109_000000_hash_guest_session_ids;
use Tests\Support\TestProject;
use jtdev\craftengagement\Plugin;

final class GuestSessionMigrationTest extends Unit
{
    public function testExistingInstallSchedulesTheGuestMigration(): void
    {
        $plugin = Plugin::getInstance();
        $plugins = Craft::$app->getPlugins();
        $schema = $plugin->schemaVersion;
        try {
            // Simulate the schema recorded by the imported installation.
            $plugin->schemaVersion = '1.0.1';
            $plugins->updatePluginVersionInfo($plugin);
            $plugin->schemaVersion = $schema;
            $plugin->getMigrator()->removeMigrationHistory('m261109_000000_hash_guest_session_ids');
            self::assertTrue($plugins->isPluginUpdatePending($plugin));
            self::assertContains('m261109_000000_hash_guest_session_ids', $plugin->getMigrator()->getNewMigrations());
        } finally {
            $plugin->schemaVersion = $schema;
            $plugins->updatePluginVersionInfo($plugin);
        }
    }

    public function testHexadecimalRawSessionIdsAreHashedAndNullSessionsAreUntouched(): void
    {
        $project = TestProject::create([
            new RatingField(['name' => 'Rating', 'handle' => 'rating']),
            new LikesField(['name' => 'Likes', 'handle' => 'likes']),
            new FavoritesField(['name' => 'Favorites', 'handle' => 'favorites']),
        ]);
        $db = Craft::$app->getDb();
        $tables = [
            ['rating', '{{%engagement_ratings_aggregate}}', '{{%engagement_ratings_votes}}', ['rating' => 4]],
            ['likes', '{{%engagement_likes_aggregate}}', '{{%engagement_likes_votes}}', ['value' => 1]],
            ['favorites', '{{%engagement_favorites_aggregate}}', '{{%engagement_favorites_entries}}', []],
        ];
        foreach ($tables as [$handle, $aggregateTable, $voteTable, $attributes]) {
            $db->createCommand()->insert($aggregateTable, [
                'elementId' => (int)$project['entry']->id,
                'fieldId' => (int)$project['fields'][$handle]->id,
                'siteId' => $project['siteId'],
            ] + ($handle === 'rating' ? ['scale' => 5] : []))->execute();
            $aggregateId = (int)$db->getLastInsertID();
            foreach ([str_repeat('a', 64), 'rawguestsession123', null] as $sessionId) {
                $db->createCommand()->insert($voteTable, $attributes + [
                    'aggregateId' => $aggregateId, 'sessionId' => $sessionId,
                ])->execute();
            }
        }

        // Craft's migration history ensures this conversion runs exactly once.
        $migration = new m261109_000000_hash_guest_session_ids(['db' => $db]);
        ob_start();
        self::assertTrue($migration->safeUp());
        ob_end_clean();

        foreach ($tables as [, , $voteTable]) {
            $rows = (new Query())->from($voteTable)->orderBy('id')->all();
            self::assertSame(SecurityHelper::hashSessionId(str_repeat('a', 64)), $rows[0]['sessionId']);
            self::assertSame(SecurityHelper::hashSessionId('rawguestsession123'), $rows[1]['sessionId']);
            self::assertNull($rows[2]['sessionId']);
        }
    }
}
