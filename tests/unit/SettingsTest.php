<?php

declare(strict_types=1);

use jtdev\craftengagement\models\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function loginUrls(): array
    {
        return [
            'relative' => ['login', true],
            'root relative' => ['/login', true],
            'with query' => ['account/login?x=1', true],
            'https' => ['https://example.com/login', true],
            'javascript' => ['javascript:alert(1)', false],
            'protocol relative' => ['//evil.example', false],
            'data uri' => ['data:text/html,x', false],
        ];
    }

    #[DataProvider('loginUrls')]
    public function testLoginUrlValidation(string $url, bool $valid): void
    {
        $settings = new Settings(['loginUrl' => $url]);
        $settings->validate(['loginUrl']);

        self::assertSame($valid, !$settings->hasErrors('loginUrl'), $url);
    }

    public function testGuestInteractionModeMustBeKnown(): void
    {
        $settings = new Settings(['guestInteractionMode' => 'popup']);
        $settings->validate(['guestInteractionMode']);

        self::assertTrue($settings->hasErrors('guestInteractionMode'));
    }
}
