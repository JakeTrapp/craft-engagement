<?php

declare(strict_types=1);

namespace Tests\integration;

use Codeception\Test\Unit;
use jtdev\craftengagement\fields\LikesField;
use jtdev\craftengagement\fields\FavoritesField;
use jtdev\craftengagement\fields\RatingField;
use jtdev\craftengagement\Plugin;
use Tests\Support\TestProject;

/**
 * Regression tests for editor-controlled values that reach the page unescaped.
 */
final class RenderSanitizationTest extends Unit
{
    protected function _before(): void
    {
        // Widgets build a login redirect URL from the current request, which the test runner doesn't have.
        $request = \Craft::$app->getRequest();
        $request->setHostInfo('https://craft-engagement-testbench.ddev.site');
        $request->setUrl('/samples/test');
    }

    private const SVG = '<svg viewBox="0 0 24 24" onload="alert(1)"><script>alert(2)</script><path d="M2 2h20v20H2z"/></svg>';

    public function testEditorSvgIsSanitizedInFrontEndRender(): void
    {
        $html = $this->renderLikes(['icon' => 'customSvg', 'likeCustomSvg' => self::SVG, 'dislikeCustomSvg' => self::SVG]);

        self::assertStringContainsString('<path d="M2 2h20v20H2z"', $html, 'Harmless SVG markup is kept.');
        self::assertStringNotContainsString('onload', $html);
        self::assertStringNotContainsString('alert(2)', $html);
        self::assertStringNotContainsString('<?xml', $html);
    }

    public function testInjectedColorIsDropped(): void
    {
        $html = $this->renderLikes(['beforeLikeColor' => 'red;background:url(https://evil.example/x)', 'afterLikeColor' => '#1d4ed8']);

        self::assertStringNotContainsString('evil.example', $html);
        self::assertStringContainsString('#1d4ed8', $html);
    }

    public function testHeadingTextCannotCloseTheInlineScript(): void
    {
        $html = $this->renderLikes(['headingText' => '</script><script>alert(3)</script>']);

        self::assertStringNotContainsString('<script>alert(3)', $html);
        // In the inline script the value is JSON with <, > hex-encoded; in markup it is HTML-escaped.
        self::assertStringContainsString('"\\u003C\\/script\\u003E\\u003Cscript\\u003E', $html);
        self::assertStringContainsString('&lt;/script&gt;&lt;script&gt;alert(3)', $html);
    }

    public function testShellModeRenderDoesNotExposeVoteState(): void
    {
        $project = TestProject::create([new RatingField(['name' => 'Rating', 'handle' => 'rating', 'scale' => 5])]);
        $value = TestProject::reload($project['entry'])->getFieldValue($project['fields']['rating']->handle);
        $html = (string)Plugin::getInstance()->twig->renderRating($value, ['mode' => 'shell']);

        self::assertStringContainsString('data-engagement-rating', $html);
        self::assertStringNotContainsString('X-CSRF-Token', $html);
    }

    public function testMembersOnlyFavoritesRemainVisibleAndDisabledFavoritesAreHidden(): void
    {
        $project = TestProject::create([new FavoritesField([
            'name' => 'Favorites', 'handle' => 'favorites',
            'allowGuestInteractions' => false, 'defaultEnabled' => true,
        ])]);
        $value = TestProject::reload($project['entry'])->getFieldValue($project['fields']['favorites']->handle);
        self::assertStringContainsString('data-engagement-favorite', (string)Plugin::getInstance()->twig->render($value));
        $value->enabled = false;
        self::assertSame('', Plugin::getInstance()->twig->render($value));
    }

    /** @param array<string, mixed> $overrides */
    private function renderLikes(array $overrides): string
    {
        $project = TestProject::create(
            [new LikesField([
                'name' => 'Likes',
                'handle' => 'likes',
                'allowEditorOverrides' => true,
                'allowOverrideIconAppearance' => true,
                'allowOverrideIconColors' => true,
                'allowOverrideHeadingText' => true,
            ])],
            ['likes' => $overrides],
        );
        $value = TestProject::reload($project['entry'])->getFieldValue($project['fields']['likes']->handle);

        return (string)Plugin::getInstance()->twig->renderLikes($value);
    }
}
