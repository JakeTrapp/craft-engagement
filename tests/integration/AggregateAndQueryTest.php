<?php

declare(strict_types=1);

namespace Tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Entry;
use jtdev\craftengagement\fields\RatingField;
use jtdev\craftengagement\models\RatingAggregate;
use jtdev\craftengagement\models\RatingVote;
use jtdev\craftengagement\Plugin;
use Tests\Support\TestProject;

final class AggregateAndQueryTest extends Unit
{
    public function testForceRecountRebuildsTotalsFromVotes(): void
    {
        $project = TestProject::create([new RatingField(['name' => 'Rating', 'handle' => 'rating', 'scale' => 5])]);
        $plugin = Plugin::getInstance();
        $aggregate = $plugin->ratingAggregates->add(new RatingAggregate([
            'elementId' => (int)$project['entry']->id,
            'fieldId' => (int)$project['fields']['rating']->id,
            'siteId' => $project['siteId'],
            'ratingSum' => 999,
            'voteCount' => 999,
            'scale' => 5,
        ]));
        foreach ([5, 3] as $i => $rating) {
            $plugin->ratingVotes->add(new RatingVote(['aggregateId' => $aggregate->id, 'sessionId' => str_repeat((string)$i, 64), 'rating' => $rating]));
        }

        $recounted = $plugin->ratingAggregates->forceRecount((int)$aggregate->id);

        self::assertSame(2, $recounted->voteCount);
        self::assertSame(8, $recounted->ratingSum);
        self::assertEqualsWithDelta(4.0, $recounted->average, 0.001);
    }

    public function testElementQueriesCanSortByRatingAverage(): void
    {
        $low = TestProject::create([new RatingField(['name' => 'Rating', 'handle' => 'rating', 'scale' => 5])]);
        $field = $low['fields']['rating'];
        $plugin = Plugin::getInstance();
        $plugin->ratingAggregates->add(new RatingAggregate([
            'elementId' => (int)$low['entry']->id, 'fieldId' => (int)$field->id, 'siteId' => $low['siteId'],
            'ratingSum' => 2, 'voteCount' => 1, 'scale' => 5,
        ]));

        $high = new Entry(['sectionId' => $low['entry']->sectionId, 'typeId' => $low['entry']->typeId, 'title' => 'High']);
        self::assertTrue(Craft::$app->getElements()->saveElement($high));
        $plugin->ratingAggregates->add(new RatingAggregate([
            'elementId' => (int)$high->id, 'fieldId' => (int)$field->id, 'siteId' => $low['siteId'],
            'ratingSum' => 5, 'voteCount' => 1, 'scale' => 5,
        ]));

        $ids = Entry::find()->sectionId($low['entry']->sectionId)->orderBy([$field->handle . '__average' => SORT_DESC])->ids();

        self::assertSame([(int)$high->id, (int)$low['entry']->id], array_map('intval', $ids));
    }

    public function testDeletePermissionIsRegistered(): void
    {
        $handles = [];
        foreach (Craft::$app->getUserPermissions()->getAllPermissions() as $group) {
            $handles = array_merge($handles, array_map('strtolower', array_keys($group['permissions'])));
        }

        self::assertContains(strtolower(Plugin::PERMISSION_DELETE_VOTES), $handles);
    }
}
