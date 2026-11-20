<?php

declare(strict_types=1);

use jtdev\craftengagement\helpers\SecurityHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecurityHelperTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function colors(): array
    {
        return [
            'short hex' => ['#fff', '#fff'],
            'long hex' => ['#1d4ed8', '#1d4ed8'],
            'hex with alpha' => ['#1d4ed880', '#1d4ed880'],
            'rgb' => ['rgb(1, 2, 3)', 'rgb(1, 2, 3)'],
            'rgba' => ['rgba(0,0,0,.5)', 'rgba(0,0,0,.5)'],
            'hsl slash syntax' => ['hsl(200 50% 40% / 0.5)', 'hsl(200 50% 40% / 0.5)'],
            'named' => ['rebeccapurple', 'rebeccapurple'],
            'custom property' => ['var(--brand)', 'var(--brand)'],
            'trims whitespace' => ['  #fff  ', '#fff'],
            'declaration injection' => ['red;background:url(//evil)', ''],
            'attribute breakout' => ['#fff" onmouseover="x', ''],
            'url' => ['url(x)', ''],
            'expression' => ['expression(alert(1))', ''],
            'empty' => ['', ''],
        ];
    }

    #[DataProvider('colors')]
    public function testSanitizeColor(string $input, string $expected): void
    {
        self::assertSame($expected, SecurityHelper::sanitizeColor($input));
    }

    public function testSanitizeColorRejectsNonStrings(): void
    {
        self::assertSame('', SecurityHelper::sanitizeColor(null));
        self::assertSame('', SecurityHelper::sanitizeColor(['#fff']));
    }

    public function testSanitizeSvgKeepsShapesAndStripsActiveContent(): void
    {
        $svg = '<svg viewBox="0 0 24 24" onload="alert(1)"><script>alert(2)</script>'
            . '<a href="javascript:alert(3)"><path d="M1 1" onclick="x()"/></a></svg>';
        $clean = (string)SecurityHelper::sanitizeSvg($svg);

        self::assertStringStartsWith('<svg', $clean);
        self::assertStringContainsString('viewBox="0 0 24 24"', $clean);
        self::assertStringContainsString('<path d="M1 1"', $clean);
        foreach (['onload', 'onclick', '<script', 'javascript:', '<?xml'] as $needle) {
            self::assertStringNotContainsString($needle, $clean);
        }
    }

    public function testSanitizeSvgReturnsNullForEmptyOrNonSvgInput(): void
    {
        self::assertNull(SecurityHelper::sanitizeSvg(null));
        self::assertNull(SecurityHelper::sanitizeSvg('   '));
        self::assertNull(SecurityHelper::sanitizeSvg('"><img src=x onerror=alert(1)>'));
    }

    public function testIsHashedSessionId(): void
    {
        self::assertTrue(SecurityHelper::isHashedSessionId(str_repeat('a1', 32)));
        self::assertFalse(SecurityHelper::isHashedSessionId('p0rk2c8v6q4fq9u1o3m5lljd7h'));
        self::assertFalse(SecurityHelper::isHashedSessionId(null));
    }
}
