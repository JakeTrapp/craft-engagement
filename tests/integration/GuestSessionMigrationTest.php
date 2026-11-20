<?php

declare(strict_types=1);

namespace Tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\db\Query;
use jtdev\craftengagement\fields\RatingField;
use jtdev\craftengagement\helpers\SecurityHelper;
use jtdev\craftengagement\migrations\m261109_000000_hash_guest_session_ids;
use Tests\Support\TestProject;

final class GuestSessionMigrationTest extends Unit
{
    public function testRawSessionIdsAreHashedOnceAndUserVotesAreUntouched(): void
    {
        $project = TestProject::create([new RatingField(['name' => 'Rating', 'handle' => 'rating', 'scale' => 5])]);
        $db = Craft::$app->getDb();
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $db->createCommand()->insert('{{%engagement_ratings_aggregate}}', [
            'elementId' => (int)$project['entry']->id, 'fieldId' => (int)$project['fields']['rating']->id, 'siteId' => $project['siteId'], 'ratingSum' => 7, 'voteCount' => 2, 'scale' => 5,
            'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => \craft\helpers\StringHelper::UUID(),
        ])->execute();
        $aggregateId = (int)$db->getLastInsertID();
        foreach ([['sessionId' => 'rawguestsession123', 'userId' => null], ['sessionId' => null, 'userId' => null]] as $row) {
            $db->createCommand()->insert('{{%engagement_ratings_votes}}', $row + [
                'aggregateId' => $aggregateId, 'rating' => 4, 'dateCreated' => $now, 'dateUpdated' => $now,
                'uid' => \craft\helpers\StringHelper::UUID(),
            ])->execute();
        }

        $migration = new m261109_000000_hash_guest_session_ids(['db' => $db]);
        ob_start();
        self::assertTrue($migration->safeUp());
        self::assertTrue($migration->safeUp(), 'Running twice must not double-hash.');
        ob_end_clean();

        $rows = (new Query())->from('{{%engagement_ratings_votes}}')->where(['aggregateId' => $aggregateId])->orderBy('id')->all();
        self::assertSame(SecurityHelper::hashSessionId('rawguestsession123'), $rows[0]['sessionId']);
        self::assertNull($rows[1]['sessionId']);
    }
}
