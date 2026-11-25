<?php

declare(strict_types=1);

use jtdev\craftengagement\helpers\EngagementQueryHelper;
use PHPUnit\Framework\TestCase;

final class EngagementQueryHelperTest extends TestCase
{
    public function testToIntOnlyAcceptsWholeNumbers(): void
    {
        self::assertSame(5, EngagementQueryHelper::toInt(5));
        self::assertSame(-3, EngagementQueryHelper::toInt(' -3 '));
        self::assertNull(EngagementQueryHelper::toInt('3.5'));
        self::assertNull(EngagementQueryHelper::toInt('1; DROP TABLE x'));
        self::assertNull(EngagementQueryHelper::toInt(null));
    }

    public function testToBool(): void
    {
        self::assertTrue(EngagementQueryHelper::toBool(true));
        self::assertTrue(EngagementQueryHelper::toBool('yes'));
        self::assertFalse(EngagementQueryHelper::toBool('0'));
        self::assertNull(EngagementQueryHelper::toBool('maybe'));
    }

    public function testToFloat(): void
    {
        self::assertSame(3.5, EngagementQueryHelper::toFloat('3.5'));
        self::assertNull(EngagementQueryHelper::toFloat('abc'));
    }

    public function testMetricSqlOnlyInterpolatesIntegers(): void
    {
        $sql = EngagementQueryHelper::ratingMetricSql(12, 'average', 3);

        self::assertStringContainsString('rAgg.fieldId = 12', $sql);
        self::assertStringContainsString('rAgg.siteId = 3', $sql);
    }
}
