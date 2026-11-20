<?php

namespace jtdev\craftengagement\helpers;

use Craft;
use craft\helpers\Html;
use Throwable;

/**
 * Shared hardening helpers for guest identity, SVG markup and CSS color values.
 */
class SecurityHelper
{
    /**
     * Allowed CSS color syntaxes: hex, rgb()/rgba()/hsl()/hsla(), named colors and CSS custom properties.
     */
    private const COLOR_PATTERN = '/^(?:#[0-9a-f]{3,8}|(?:rgb|rgba|hsl|hsla)\(\s*[0-9.,%\s\/+-]{1,64}\)|[a-z]{3,30}|var\(--[a-z0-9_-]{1,64}\))$/i';

    /**
     * Returns a stable, non-reversible identifier for the current guest session.
     *
     * Raw PHP session IDs are never written to the database; only an HMAC of them is,
     * so a database dump or the moderation UI cannot be used to hijack a guest session.
     */
    public static function currentGuestToken(): string
    {
        $session = Craft::$app->getSession();
        $session->open();

        return self::hashSessionId((string)$session->getId());
    }

    /**
     * HMAC a session ID with the site's security key.
     */
    public static function hashSessionId(string $sessionId): string
    {
        return hash_hmac('sha256', $sessionId, Craft::$app->getConfig()->getGeneral()->securityKey);
    }

    /**
     * Whether a stored session identifier has already been hashed.
     */
    public static function isHashedSessionId(?string $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/', $value) === 1;
    }

    /**
     * Strip scripts, event handlers and other active content from user-supplied SVG markup.
     */
    public static function sanitizeSvg(mixed $svg): ?string
    {
        if (!is_string($svg)) {
            return null;
        }

        $svg = trim($svg);
        if ($svg === '') {
            return null;
        }

        try {
            $sanitized = Html::sanitizeSvg($svg);
            // The sanitizer emits an XML declaration, which does not belong in inline HTML.
            $sanitized = trim((string)preg_replace('/^\s*<\?xml[^>]*\?>/i', '', $sanitized));
        } catch (Throwable) {
            return null;
        }

        return $sanitized !== '' ? $sanitized : null;
    }

    /**
     * Return the color unchanged if it is a plain CSS color value, otherwise an empty string.
     * This prevents CSS declaration injection through color settings rendered into style attributes.
     */
    public static function sanitizeColor(mixed $color): string
    {
        if (!is_string($color)) {
            return '';
        }

        $color = trim($color);

        return ($color !== '' && preg_match(self::COLOR_PATTERN, $color) === 1) ? $color : '';
    }
}
