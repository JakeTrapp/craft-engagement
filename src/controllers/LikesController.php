<?php

namespace jtdev\craftengagement\controllers;

use Craft;
use craft\web\Controller;
use jtdev\craftengagement\fields\LikesField;
use jtdev\craftengagement\helpers\SecurityHelper;
use jtdev\craftengagement\models\LikesAggregate;
use jtdev\craftengagement\models\LikesVote;
use jtdev\craftengagement\Plugin;
use jtdev\craftengagement\records\LikesAggregateRecord;
use Throwable;
use yii\db\IntegrityException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Likes/dislikes endpoints.
 */
class LikesController extends Controller
{
    use ResolvesEngagementTarget;

    protected array|bool|int $allowAnonymous = ['cast-like'];

    /**
     * Cast or toggle a like/dislike vote.
     *
     * Required body params:
     * - elementId
     * - fieldId
     * - value (1 for like, -1 for dislike)
     *
     * Optional:
     * - siteId (defaults to current site)
     */
    public function actionCastLike(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();

        $value = (int)$request->getRequiredBodyParam('value');

        if (!in_array($value, [-1, 1], true)) {
            throw new BadRequestHttpException('Like value must be 1 or -1.');
        }

        /** @var LikesField $field */
        [$element, $field, $elementId, $fieldId, $siteId] = $this->resolveEngagementTarget(LikesField::class);

        /** @var mixed $fieldValue */
        $fieldValue = $element->getFieldValue($field->handle);
        $normalized = $fieldValue instanceof \jtdev\craftengagement\models\Like
            ? $fieldValue
            : $field->normalizeValue($fieldValue, $element);

        if (!$normalized->enabled) {
            throw new ForbiddenHttpException('Likes are disabled for this element.');
        }

        $aggregateService = Plugin::getInstance()->likesAggregates;
        $voteService = Plugin::getInstance()->likesVotes;

        $aggregate = $aggregateService->getByElementFieldSite($elementId, $fieldId, $siteId);
        if ($aggregate === null) {
            try {
                $aggregate = $aggregateService->add(new LikesAggregate([
                    'elementId' => $elementId,
                    'fieldId' => $fieldId,
                    'siteId' => $siteId,
                    'likeCount' => 0,
                    'dislikeCount' => 0,
                ]));
            } catch (IntegrityException) {
                $aggregate = null;
            }

            if ($aggregate === null) {
                $aggregate = $aggregateService->getByElementFieldSite($elementId, $fieldId, $siteId);
            }
        }

        if ($aggregate === null) {
            throw new BadRequestHttpException('Could not initialize likes aggregate.');
        }

        $currentUser = Craft::$app->getUser()->getIdentity();
        $sessionId = null;

        if ($currentUser === null) {
            if (!$normalized->allowGuestInteractions) {
                throw new ForbiddenHttpException('Guest likes are disabled for this field.');
            }
            $sessionId = SecurityHelper::currentGuestToken();
        }

        $existingVote = $currentUser !== null
            ? $voteService->getByAggregateAndUserId($aggregate->id, (int)$currentUser->id)
            : $voteService->getByAggregateAndSessionId($aggregate->id, (string)$sessionId);

        if ($existingVote !== null && !$normalized->allowVoteChange) {
            throw new ForbiddenHttpException('Changing an existing like/dislike is disabled for this field.');
        }

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $savedVote = null;
            $userVote = null;
            $likeCountDelta = 0;
            $dislikeCountDelta = 0;

            if ($existingVote !== null && (int)$existingVote->value === $value) {
                // Only decrement when this request actually removed the row, so concurrent
                // toggles cannot push the counters below the real number of votes.
                $deleted = $voteService->delete($existingVote->id);
                if (!$deleted) {
                    $likeCountDelta = 0;
                } elseif ($value === 1) {
                    $likeCountDelta = -1;
                } else {
                    $dislikeCountDelta = -1;
                }
            } elseif ($existingVote !== null) {
                $savedVote = $voteService->update($existingVote->id, ['value' => $value]);
                if ($savedVote === null) {
                    throw new BadRequestHttpException('Could not save like/dislike vote.');
                }
                $userVote = $savedVote->value;
                if ((int)$existingVote->value === 1) {
                    $likeCountDelta = -1;
                    $dislikeCountDelta = 1;
                } else {
                    $likeCountDelta = 1;
                    $dislikeCountDelta = -1;
                }
            } else {
                try {
                    $savedVote = $voteService->add(new LikesVote([
                        'aggregateId' => $aggregate->id,
                        'userId' => $currentUser !== null ? (int)$currentUser->id : null,
                        'sessionId' => $currentUser === null ? (string)$sessionId : null,
                        'value' => $value,
                    ]));
                    if ($savedVote === null) {
                        throw new BadRequestHttpException('Could not save like/dislike vote.');
                    }
                    $userVote = $savedVote->value;
                    if ($value === 1) {
                        $likeCountDelta = 1;
                    } else {
                        $dislikeCountDelta = 1;
                    }
                } catch (IntegrityException) {
                    $concurrentVote = $currentUser !== null
                        ? $voteService->getByAggregateAndUserId($aggregate->id, (int)$currentUser->id)
                        : $voteService->getByAggregateAndSessionId($aggregate->id, (string)$sessionId);

                    if ($concurrentVote === null) {
                        throw new BadRequestHttpException('Could not save like/dislike vote.');
                    }

                    if (!$normalized->allowVoteChange) {
                        $savedVote = $concurrentVote;
                        $userVote = $savedVote->value;
                    } elseif ((int)$concurrentVote->value !== $value) {
                        $savedVote = $voteService->update($concurrentVote->id, ['value' => $value]);
                        if ($savedVote === null) {
                            throw new BadRequestHttpException('Could not save like/dislike vote.');
                        }
                        $userVote = $savedVote->value;
                        if ((int)$concurrentVote->value === 1) {
                            $likeCountDelta = -1;
                            $dislikeCountDelta = 1;
                        } else {
                            $likeCountDelta = 1;
                            $dislikeCountDelta = -1;
                        }
                    } else {
                        $savedVote = $concurrentVote;
                        $userVote = $savedVote->value;
                    }
                }
            }

            LikesAggregateRecord::updateAllCounters([
                'likeCount' => $likeCountDelta,
                'dislikeCount' => $dislikeCountDelta,
            ], ['id' => $aggregate->id]);
            $aggregate = $aggregateService->getById($aggregate->id);

            if ($aggregate === null) {
                throw new BadRequestHttpException('Could not update likes aggregate.');
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
                'likeCount' => $aggregate->likeCount,
                'dislikeCount' => $aggregate->dislikeCount,
            ],
            'vote' => [
                'id' => $savedVote?->id,
                'value' => $userVote,
            ],
        ]);
    }
}
