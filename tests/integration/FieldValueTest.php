<?php

declare(strict_types=1);

namespace Tests\integration;

use Codeception\Test\Unit;
use jtdev\craftengagement\fields\LikesField;
use jtdev\craftengagement\fields\RatingField;
use jtdev\craftengagement\models\Like;
use jtdev\craftengagement\models\Rating;
use Tests\Support\TestProject;

final class FieldValueTest extends Unit
{
    public function testRatingValueRoundTripsAndClampsEditorScale(): void
    {
        $project = TestProject::create(
            [new RatingField(['name' => 'Rating', 'handle' => 'rating', 'scale' => 5, 'allowEditorOverrides' => true])],
            ['rating' => ['scale' => 9999, 'headingText' => 'Rated']],
        );
        $field = $project['fields']['rating'];
        $value = TestProject::reload($project['entry'])->getFieldValue($field->handle);

        self::assertInstanceOf(Rating::class, $value);
        self::assertSame(100, $value->scale, 'Editor scale overrides are clamped to maxScale.');
        self::assertSame('Rated', $value->headingText);
        self::assertSame((int)$project['entry']->id, $value->elementId);
    }

    public function testEditorOverridesAreIgnoredWhenTheFieldDisallowsThem(): void
    {
        $project = TestProject::create(
            [new LikesField(['name' => 'Likes', 'handle' => 'likes', 'allowEditorOverrides' => false, 'allowGuestInteractions' => false])],
            ['likes' => ['allowGuestInteractions' => true, 'enabled' => false, 'headingText' => 'Editor heading']],
        );
        $field = $project['fields']['likes'];
        $value = TestProject::reload($project['entry'])->getFieldValue($field->handle);

        self::assertInstanceOf(Like::class, $value);
        self::assertFalse($value->allowGuestInteractions, 'Guests must not be enabled by an editor when overrides are off.');
        self::assertTrue($value->enabled);
        self::assertNull($value->headingText);
    }

    public function testInvalidIconOverrideFallsBackToFieldDefault(): void
    {
        $project = TestProject::create(
            [new LikesField(['name' => 'Likes', 'handle' => 'likes', 'allowEditorOverrides' => true])],
            ['likes' => ['icon' => '<script>']],
        );
        $value = TestProject::reload($project['entry'])->getFieldValue($project['fields']['likes']->handle);

        self::assertSame('thumbs', $value->icon);
    }
}
