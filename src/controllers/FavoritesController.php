<?php

namespace jtdev\craftengagement\controllers;

use Craft;
use craft\web\Controller;
use jtdev\craftengagement\fields\FavoritesField;
use jtdev\craftengagement\helpers\SecurityHelper;
use jtdev\craftengagement\models\FavoritesAggregate;
use jtdev\craftengagement\models\FavoritesEntry;
use jtdev\craftengagement\Plugin;
use jtdev\craftengagement\records\FavoritesAggregateRecord;
use Throwable;
use yii\db\IntegrityException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Favorites endpoints.
 */
class FavoritesController extends Controller
{
    use ResolvesEngagementTarget;

    protected array|bool|int $allowAnonymous = ['toggle-favorite'];

    /**
     * Toggle favorite on/off for an element+field+site row.
     */
    public function actionToggleFavorite(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        /** @var FavoritesField $field */
        [$element, $field, $elementId, $fieldId, $siteId] = $this->resolveEngagementTarget(FavoritesField::class);

        /** @var mixed $fieldValue */
        $fieldValue = $element->getFieldValue($field->handle);
        $normalized = $fieldValue instanceof \jtdev\craftengagement\models\Favorite
            ? $fieldValue
            : $field->normalizeValue($fieldValue, $element);

        if (!$normalized->enabled) {
            throw new ForbiddenHttpException('Favorites are disabled for this element.');
        }

        $aggregateService = Plugin::getInstance()->favoritesAggregates;
        $entryService = Plugin::getInstance()->favoritesEntries;

        $aggregate = $aggregateService->getByElementFieldSite($elementId, $fieldId, $siteId);
        if ($aggregate === null) {
            try {
                $aggregate = $aggregateService->add(new FavoritesAggregate([
                    'elementId' => $elementId,
                    'fieldId' => $fieldId,
                    'siteId' => $siteId,
                    'favoriteCount' => 0,
                ]));
            } catch (IntegrityException) {
                $aggregate = null;
            }

            if ($aggregate === null) {
                $aggregate = $aggregateService->getByElementFieldSite($elementId, $fieldId, $siteId);
            }
        }

        if ($aggregate === null) {
            throw new BadRequestHttpException('Could not initialize favorites aggregate.');
        }

        $currentUser = Craft::$app->getUser()->getIdentity();
        $sessionId = null;

        if ($currentUser === null) {
            if (!$normalized->allowGuestInteractions) {
                throw new ForbiddenHttpException('Guest favorites are disabled for this field.');
            }
            $sessionId = SecurityHelper::currentGuestToken();
        }

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            // Serialize mutations before reading the previous vote; stale reads can
            // otherwise apply the same rating/like delta more than once.
            $db->createCommand('SELECT [[id]] FROM ' . $db->quoteTableName(FavoritesAggregateRecord::tableName()) . ' WHERE [[id]] = :id FOR UPDATE', [':id' => $aggregate->id])->queryScalar();
            $existingEntry = $currentUser !== null
                ? $entryService->getByAggregateAndUserId($aggregate->id, (int)$currentUser->id)
                : $entryService->getByAggregateAndSessionId($aggregate->id, (string)$sessionId);

            $savedEntry = null;
            $isFavorited = false;
            $favoriteCountDelta = 0;

            if ($existingEntry !== null) {
                // Only decrement when this request actually removed the row, so concurrent
                // toggles cannot push the counter below the real number of favorites.
                $isFavorited = false;
                $favoriteCountDelta = $entryService->delete($existingEntry->id) ? -1 : 0;
            } else {
                try {
                    $savedEntry = $entryService->add(new FavoritesEntry([
                        'aggregateId' => $aggregate->id,
                        'userId' => $currentUser !== null ? (int)$currentUser->id : null,
                        'sessionId' => $currentUser === null ? (string)$sessionId : null,
                    ]));
                    if ($savedEntry === null) {
                        throw new BadRequestHttpException('Could not save favorite entry.');
                    }
                    $isFavorited = true;
                    $favoriteCountDelta = 1;
                } catch (IntegrityException) {
                    $savedEntry = $currentUser !== null
                        ? $entryService->getByAggregateAndUserId($aggregate->id, (int)$currentUser->id)
                        : $entryService->getByAggregateAndSessionId($aggregate->id, (string)$sessionId);

                    if ($savedEntry === null) {
                        throw new BadRequestHttpException('Could not save favorite entry.');
                    }
                    $isFavorited = true;
                    $favoriteCountDelta = 0;
                }
            }

            FavoritesAggregateRecord::updateAllCounters([
                'favoriteCount' => $favoriteCountDelta,
            ], ['id' => $aggregate->id]);
            $aggregate = $aggregateService->getById($aggregate->id);

            if ($aggregate === null) {
                throw new BadRequestHttpException('Could not update favorites aggregate.');
            }

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $this->asJson([
            'success' => true,
            'aggregate' => [
                'id' => $aggregate->id,
                'elementId' => $aggregate->elementId,
                'fieldId' => $aggregate->fieldId,
                'siteId' => $aggregate->siteId,
                'favoriteCount' => $aggregate->favoriteCount,
            ],
            'entry' => [
                'id' => $savedEntry?->id,
                'isFavorited' => $isFavorited,
            ],
        ]);
    }
}
