<?php

namespace jtdev\craftengagement\models;

use craft\base\Model;

/**
 * Engagement plugin settings.
 */
class Settings extends Model
{
    public const ICON_STAR = 'star';
    public const ICON_HEART = 'heart';
    public const ICON_THUMBS = 'thumbs';
    public const ICON_CUSTOM_SVG = 'customSvg';
    public const GUEST_INTERACTION_MODE_MESSAGE = 'message';
    public const GUEST_INTERACTION_MODE_REDIRECT = 'redirect';

    public string $loginUrl = 'login';
    public string $loginRedirectParam = 'redirect';
    public string $guestInteractionMode = self::GUEST_INTERACTION_MODE_MESSAGE;
    public string $guestInteractionMessage = 'Please log in or register to interact.';

    /**
     * @return array<int, array<int|string, mixed>>
     */
    public function rules(): array
    {
        return [
            [['loginUrl'], 'required'],
            [['guestInteractionMode'], 'required'],
            [['loginUrl', 'loginRedirectParam', 'guestInteractionMode', 'guestInteractionMessage'], 'string'],
            // Only site-relative paths or http(s) URLs; blocks javascript:, data: and protocol-relative values.
            [['loginUrl'], 'match', 'pattern' => '#^(?:https?://[^\s]+|/?(?!/)[^\s:]*)$#i', 'message' => 'Login URL must be a relative path or an http(s) URL.'],
            [['loginRedirectParam'], 'match', 'pattern' => '/^[A-Za-z0-9_\-\[\]]*$/', 'message' => 'Redirect parameter may only contain letters, numbers, dashes, underscores and brackets.'],
            [['guestInteractionMode'], 'in', 'range' => [
                self::GUEST_INTERACTION_MODE_MESSAGE,
                self::GUEST_INTERACTION_MODE_REDIRECT,
            ]],
        ];
    }

    public function beforeValidate(): bool
    {
        $this->loginUrl = trim($this->loginUrl);
        $this->loginRedirectParam = trim($this->loginRedirectParam);
        $this->guestInteractionMode = trim($this->guestInteractionMode);
        $this->guestInteractionMessage = trim($this->guestInteractionMessage);

        return parent::beforeValidate();
    }
}
