<?php

namespace jtdev\craftengagement\variables;

use jtdev\craftengagement\helpers\SecurityHelper;
use jtdev\craftengagement\Plugin;
use Twig\Markup;

/**
 * Exposes plugin helpers to Twig as craft.engagement.*
 */
class EngagementVariable
{
    /** Register the external deferred-widget initializer for this response. */
    public function registerDeferredAssets(): void
    {
        Plugin::getInstance()->twig->registerDeferredAssets();
    }

    /**
     * Sanitize custom SVG markup (strips scripts, event handlers and other active content).
     * Used by the widget templates; also handy for custom widget template overrides.
     */
    public function sanitizeSvg(mixed $svg): ?string
    {
        return SecurityHelper::sanitizeSvg($svg);
    }

    /**
     * Return the value if it is a plain CSS color, otherwise an empty string.
     */
    public function sanitizeColor(mixed $color): string
    {
        return SecurityHelper::sanitizeColor($color);
    }

    /**
     * Render UI by inferring the field value type automatically.
     */
    public function render(mixed $fieldValue, ?array $options = null): Markup|string
    {
        return Plugin::getInstance()->twig->render($fieldValue, $options);
    }

    /**
     * Render the default ratings UI snippet.
     */
    public function renderRating(mixed $fieldValue, ?array $options = null): Markup|string
    {
        return Plugin::getInstance()->twig->renderRating($fieldValue, $options);
    }

    /**
     * Render the default likes UI snippet.
     */
    public function renderLikes(mixed $fieldValue, ?array $options = null): Markup|string
    {
        return Plugin::getInstance()->twig->renderLikes($fieldValue, $options);
    }

    /**
     * Render the default favorites UI snippet.
     */
    public function renderFavorite(mixed $fieldValue, ?array $options = null): Markup|string
    {
        return Plugin::getInstance()->twig->renderFavorite($fieldValue, $options);
    }
}
