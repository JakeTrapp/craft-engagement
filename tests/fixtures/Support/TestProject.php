<?php

declare(strict_types=1);

namespace Tests\Support;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\helpers\StringHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use RuntimeException;

/**
 * Builds a throwaway section + entry type + entry around the given fields.
 * Integration tests run inside a transaction, so everything is rolled back afterwards.
 */
final class TestProject
{
    /**
     * @param array<string, mixed> $fieldValues
     * @return array{entry: Entry, siteId: int, fields: array<string, FieldInterface>}
     */
    public static function create(array $fields, array $fieldValues = [], bool $enabled = true): array
    {
        $suffix = strtolower(StringHelper::randomString(6));
        $saved = [];
        foreach ($fields as $field) {
            $field->handle .= ucfirst($suffix);
            if (!Craft::$app->getFields()->saveField($field)) {
                throw new RuntimeException('Could not save field: ' . implode(' ', $field->getErrorSummary(true)));
            }
            $saved[$field->handle] = $field;
        }

        $layout = new FieldLayout(['type' => Entry::class]);
        $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
        $tab->setElements([new EntryTitleField(), ...array_map(static fn(FieldInterface $f) => new CustomField($f), $saved)]);
        $layout->setTabs([$tab]);

        $entryType = new EntryType(['name' => 'Type ' . $suffix, 'handle' => 'type' . ucfirst($suffix)]);
        $entryType->setFieldLayout($layout);
        if (!Craft::$app->getEntries()->saveEntryType($entryType)) {
            throw new RuntimeException('Could not save entry type: ' . implode(' ', $entryType->getErrorSummary(true)));
        }

        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $section = new Section([
            'name' => 'Section ' . $suffix,
            'handle' => 'section' . ucfirst($suffix),
            'type' => Section::TYPE_CHANNEL,
            'siteSettings' => [new Section_SiteSettings(['siteId' => $siteId, 'hasUrls' => false])],
        ]);
        $section->setEntryTypes([$entryType]);
        if (!Craft::$app->getEntries()->saveSection($section)) {
            throw new RuntimeException('Could not save section: ' . implode(' ', $section->getErrorSummary(true)));
        }

        $entry = new Entry(['sectionId' => $section->id, 'typeId' => $entryType->id, 'title' => 'Entry ' . $suffix, 'enabled' => $enabled]);
        foreach ($fieldValues as $baseHandle => $value) {
            $entry->setFieldValue($baseHandle . ucfirst($suffix), $value);
        }
        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new RuntimeException('Could not save entry: ' . implode(' ', $entry->getErrorSummary(true)));
        }

        // Index fields by their base handle so tests don't need to know the suffix.
        $byBase = [];
        foreach ($saved as $handle => $field) {
            $byBase[substr($handle, 0, -strlen($suffix))] = $field;
        }

        return ['entry' => $entry, 'siteId' => $siteId, 'fields' => $byBase];
    }

    public static function reload(Entry $entry): Entry
    {
        return Entry::find()->id($entry->id)->siteId($entry->siteId)->status(null)->one()
            ?? throw new RuntimeException('Entry disappeared.');
    }
}
