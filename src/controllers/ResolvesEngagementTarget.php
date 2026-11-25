<?php

namespace jtdev\craftengagement\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\base\NestedElementInterface;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;

/**
 * Shared request validation for the public vote endpoints.
 */
trait ResolvesEngagementTarget
{
    /**
     * Resolve and validate the element, field and site targeted by a public vote request.
     *
     * - The site must exist.
     * - The element must be publicly visible in that site (its element type's default status, e.g. "live"
     *   for entries) and must not be a draft or revision.
     * - The field must be of the expected type and actually belong to the element's field layout.
     *
     * @template T of FieldInterface
     * @param class-string<T> $fieldClass
     * @return array{0: ElementInterface, 1: T, 2: int, 3: int, 4: int}
     *         [element, field (layout instance), elementId, fieldId, siteId]
     * @throws BadRequestHttpException
     * @throws NotFoundHttpException
     */
    protected function resolveEngagementTarget(string $fieldClass): array
    {
        $request = Craft::$app->getRequest();
        $sites = Craft::$app->getSites();

        $elementId = (int)$request->getRequiredBodyParam('elementId');
        $fieldId = (int)$request->getRequiredBodyParam('fieldId');
        $siteIdParam = $request->getBodyParam('siteId');
        $siteId = ($siteIdParam === null || $siteIdParam === '')
            ? (int)$sites->getCurrentSite()->id
            : (int)$siteIdParam;

        if ($elementId <= 0 || $fieldId <= 0 || $siteId <= 0) {
            throw new BadRequestHttpException('Invalid request parameters.');
        }

        if ($sites->getSiteById($siteId) === null) {
            throw new BadRequestHttpException('Invalid site.');
        }

        $elementType = Craft::$app->getElements()->getElementTypeById($elementId);
        if ($elementType === null || !class_exists($elementType) || !is_subclass_of($elementType, ElementInterface::class)) {
            throw new NotFoundHttpException('Element not found.');
        }

        // Use the element type's default query criteria so disabled, pending, expired,
        // draft and revision elements cannot be voted on by guessing their IDs.
        /** @var ElementInterface|null $element */
        $element = $elementType::find()
            ->id($elementId)
            ->siteId($siteId)
            ->one();

        if ($element === null || !$this->hasPublishedOwner($element, $siteId)) {
            throw new NotFoundHttpException('Element not found.');
        }

        $field = $element->getFieldLayout()?->getFieldById($fieldId);
        if (!$field instanceof $fieldClass) {
            throw new BadRequestHttpException('Invalid field for this element.');
        }

        return [$element, $field, $elementId, $fieldId, $siteId];
    }

    /**
     * A live nested entry is public only through a published chain of owners.
     * Shared entries can be visible through any owner; reject cycles and missing owners.
     *
     * @param array<int, true> $visited
     */
    private function hasPublishedOwner(ElementInterface $element, int $siteId, array $visited = []): bool
    {
        if (isset($visited[$element->id])) {
            return false;
        }
        $visited[$element->id] = true;

        if (!$element instanceof NestedElementInterface || $element->getPrimaryOwnerId() === null) {
            return true;
        }

        // Multiple ownership was added in Craft 5.8.17; retain the Craft 5.0 API fallback.
        $owners = version_compare(Craft::$app->getVersion(), '5.8.17', '>=')
            ? $element->getOwners()
            : [$element->getPrimaryOwner()];
        foreach ($owners as $owner) {
            if ($owner === null) {
                continue;
            }
            // Owner getters deliberately include unpublished/draft/other-site elements,
            // so re-query using the same public criteria as the vote target.
            $published = $owner::find()->id($owner->id)->siteId($siteId)->one();
            if ($published !== null && $this->hasPublishedOwner($published, $siteId, $visited)) {
                return true;
            }
        }

        return false;
    }

}
