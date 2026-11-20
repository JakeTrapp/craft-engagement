<?php

declare(strict_types=1);

namespace modules\engagementtestbench\console\controllers;

use Craft;
use craft\base\FieldInterface;
use craft\console\Controller;
use craft\elements\Entry;
use craft\elements\User;
use craft\enums\CmsEdition;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\helpers\StringHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use jtdev\craftengagement\fields\FavoritesField;
use jtdev\craftengagement\fields\LikesField;
use jtdev\craftengagement\fields\RatingField;
use jtdev\craftengagement\Plugin;
use RuntimeException;
use yii\console\ExitCode;

/**
 * Seeds the disposable browser-test fixture and prints it as one JSON line.
 *
 * Run only against the marked db_test database (the browser global setup enforces this).
 */
final class SeedController extends Controller
{
    public const MALICIOUS_SVG = '<svg viewBox="0 0 24 24" onload="window.__engagementXss=1"><script>window.__engagementXss=2</script><path d="M2 2h20v20H2z"/></svg>';
    public const MALICIOUS_COLOR = 'red;background:url(https://evil.example/x)';
    public const MALICIOUS_HEADING = '</script><script>window.__engagementXss=3</script>';

    public function actionBrowser(): int
    {
        $password = getenv('ENGAGEMENT_BROWSER_PASSWORD') ?: '';
        if (strlen($password) < 12) {
            throw new RuntimeException('Set ENGAGEMENT_BROWSER_PASSWORD (12+ chars) for the generated test users.');
        }

        // User permissions need Team/Pro; trial editions are allowed in dev mode.
        Craft::$app->setEdition(CmsEdition::Pro);
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;

        $rating = $this->saveField(new RatingField([
            'name' => 'Engagement Rating',
            'handle' => 'engagementRating',
            'scale' => 5,
            'allowGuestRatings' => true,
            'allowUserRatingChange' => true,
        ]));
        $likes = $this->saveField(new LikesField([
            'name' => 'Engagement Likes',
            'handle' => 'engagementLikes',
            'allowGuestInteractions' => true,
            'allowUserVoteChange' => true,
            'likeText' => 'Like ({likes})',
            'dislikeText' => 'Dislike ({dislikes})',
        ]));
        $favorites = $this->saveField(new FavoritesField([
            'name' => 'Engagement Favorites',
            'handle' => 'engagementFavorites',
            'allowGuestInteractions' => false,
            'favoriteText' => 'Favorite',
            'unfavoriteText' => 'Unfavorite',
        ]));
        $iconLikes = $this->saveField(new LikesField([
            'name' => 'Editor Icon Likes',
            'handle' => 'iconLikes',
            'allowGuestInteractions' => true,
            'allowEditorOverrides' => true,
            'allowOverrideIconAppearance' => true,
            'allowOverrideIconColors' => true,
            'allowOverrideHeadingText' => true,
        ]));

        $sampleType = $this->saveEntryType('Engagement Sample', 'engagementSample', [$rating, $likes, $favorites, $iconLikes]);
        $plainType = $this->saveEntryType('Plain Page', 'plainPage', []);
        $samples = $this->saveSection('Samples', 'samples', $sampleType, $siteId);
        $plain = $this->saveSection('Plain', 'plain', $plainType, $siteId);

        $admin = User::find()->admin(true)->one() ?? throw new RuntimeException('No admin user found; install Craft first.');

        $published = $this->saveEntry($samples, $sampleType, 'Published sample', true, $admin);
        $disabled = $this->saveEntry($samples, $sampleType, 'Disabled sample', false, $admin);
        $xss = $this->saveEntry($samples, $sampleType, 'Editor icon sample', true, $admin, [
            'iconLikes' => [
                'icon' => 'customSvg',
                'likeCustomSvg' => self::MALICIOUS_SVG,
                'dislikeCustomSvg' => self::MALICIOUS_SVG,
                'beforeLikeColor' => self::MALICIOUS_COLOR,
                'afterLikeColor' => '#1d4ed8',
                'headingText' => self::MALICIOUS_HEADING,
            ],
        ]);
        $plainEntry = $this->saveEntry($plain, $plainType, 'Plain page', true, $admin);
        $draft = Craft::$app->getDrafts()->createDraft($published, (int)$admin->id, 'Browser draft');

        $member = $this->saveUser('member', $password, []);
        $moderator = $this->saveUser('moderator', $password, ['accessCp', 'accessPlugin-engagement']);
        $deleter = $this->saveUser('deleter', $password, ['accessCp', 'accessPlugin-engagement', Plugin::PERMISSION_DELETE_VOTES]);

        $this->stdout(json_encode([
            'siteId' => $siteId,
            'adminUsername' => (string)$admin->username,
            'password' => $password,
            'users' => [
                'member' => $member->username,
                'moderator' => $moderator->username,
                'deleter' => $deleter->username,
            ],
            'fields' => [
                'rating' => (int)$rating->id,
                'likes' => (int)$likes->id,
                'favorites' => (int)$favorites->id,
                'iconLikes' => (int)$iconLikes->id,
            ],
            'entries' => [
                'published' => ['id' => (int)$published->id, 'url' => $published->getUrl()],
                'disabled' => ['id' => (int)$disabled->id],
                'xss' => ['id' => (int)$xss->id, 'url' => $xss->getUrl()],
                'plain' => ['id' => (int)$plainEntry->id, 'url' => $plainEntry->getUrl()],
                'draft' => ['id' => (int)$draft->id],
            ],
        ], JSON_UNESCAPED_SLASHES) . "\n");

        return ExitCode::OK;
    }

    /**
     * @template T of FieldInterface
     * @param T $field
     * @return T
     */
    private function saveField(FieldInterface $field): FieldInterface
    {
        if (!Craft::$app->getFields()->saveField($field)) {
            throw new RuntimeException(sprintf('Could not save field %s: %s', $field->handle, implode(' ', $field->getErrorSummary(true))));
        }

        return $field;
    }

    /** @param FieldInterface[] $fields */
    private function saveEntryType(string $name, string $handle, array $fields): EntryType
    {
        $layout = new FieldLayout(['type' => Entry::class]);
        $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
        $tab->setElements([
            new EntryTitleField(),
            ...array_map(static fn(FieldInterface $field) => new CustomField($field), $fields),
        ]);
        $layout->setTabs([$tab]);

        $entryType = new EntryType(['name' => $name, 'handle' => $handle, 'uid' => StringHelper::UUID()]);
        $entryType->setFieldLayout($layout);
        if (!Craft::$app->getEntries()->saveEntryType($entryType)) {
            throw new RuntimeException(sprintf('Could not save entry type %s: %s', $handle, implode(' ', $entryType->getErrorSummary(true))));
        }

        return $entryType;
    }

    private function saveSection(string $name, string $handle, EntryType $entryType, int $siteId): Section
    {
        $section = new Section([
            'name' => $name,
            'handle' => $handle,
            'type' => Section::TYPE_CHANNEL,
            'siteSettings' => [
                new Section_SiteSettings([
                    'siteId' => $siteId,
                    'enabledByDefault' => true,
                    'hasUrls' => true,
                    'uriFormat' => $handle . '/{slug}',
                    'template' => 'samples/_entry',
                ]),
            ],
        ]);
        $section->setEntryTypes([$entryType]);
        if (!Craft::$app->getEntries()->saveSection($section)) {
            throw new RuntimeException(sprintf('Could not save section %s: %s', $handle, implode(' ', $section->getErrorSummary(true))));
        }

        return $section;
    }

    /** @param array<string, mixed> $fieldValues */
    private function saveEntry(Section $section, EntryType $type, string $title, bool $enabled, User $author, array $fieldValues = []): Entry
    {
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $type->id,
            'title' => $title,
            'enabled' => $enabled,
        ]);
        $entry->setAuthors([$author]);
        foreach ($fieldValues as $handle => $value) {
            $entry->setFieldValue($handle, $value);
        }
        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new RuntimeException(sprintf('Could not save entry %s: %s', $title, implode(' ', $entry->getErrorSummary(true))));
        }

        return $entry;
    }

    /** @param string[] $permissions */
    private function saveUser(string $username, string $password, array $permissions): User
    {
        $user = new User([
            'username' => $username,
            'email' => $username . '@engagement-testbench.test',
            'newPassword' => $password,
            'active' => true,
        ]);
        if (!Craft::$app->getElements()->saveElement($user, false)) {
            throw new RuntimeException(sprintf('Could not save user %s: %s', $username, implode(' ', $user->getErrorSummary(true))));
        }
        if ($permissions !== []) {
            Craft::$app->getUserPermissions()->saveUserPermissions((int)$user->id, $permissions);
        }

        return $user;
    }
}
