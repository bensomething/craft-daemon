<?php

namespace bensomething\daemon\controllers;

use bensomething\daemon\Plugin;
use Craft;
use craft\web\Controller;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Taking level stats off the browser, and handing back the board.
 *
 * Scoped to the logged-in user the same way savegames are: the user id comes
 * from the session rather than the request, so a player can add to their own
 * record and nobody else's. Reading is not scoped, because a leaderboard that
 * only shows you your own times is a diary.
 */
class StatsController extends Controller
{
    /**
     * @throws ForbiddenHttpException if the user can't play, or the admin has
     * turned the board off.
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(PlayController::PERMISSION_PLAY);

        // The setting is not decoration: with it off nothing should be recorded
        // and there is nothing to read, so the actions go away rather than
        // quietly doing nothing.
        if (!Plugin::getInstance()->getSettings()->leaderboard) {
            throw new ForbiddenHttpException('The leaderboard is turned off.');
        }

        return true;
    }

    /**
     * Takes the levels the engine has finished since the last time it was asked.
     *
     * Each carries the raw line from levelstat.txt and the skill the host read
     * off stdout. The line is sent raw rather than parsed in the browser
     * because there is one parser, it is in PHP, and it is the thing with tests.
     *
     * @throws BadRequestHttpException if the request isn't a JSON POST, names no
     * game, or carries no levels.
     */
    public function actionRecord(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $wadKey = $this->wadKey();
        $levels = $this->request->getBodyParam('levels');

        if (!is_array($levels)) {
            throw new BadRequestHttpException('No levels were posted.');
        }

        try {
            $applied = Plugin::getInstance()->getStats()->record(
                (int)Craft::$app->getUser()->getId(),
                $wadKey,
                $levels,
            );
        } catch (Throwable $e) {
            Craft::warning("Could not record level stats: {$e->getMessage()}", __METHOD__);

            return $this->asFailure(Craft::t('daemon', 'Could not record that.'));
        }

        // The count matters to the browser: it only advances past the lines it
        // has sent once they have actually landed, so a failed request is
        // retried on the next level rather than lost.
        return $this->asSuccess(data: ['recorded' => $applied]);
    }

    /**
     * The board, rendered, for the slideout to show.
     *
     * HTML rather than JSON because it is a table: building one in jQuery to
     * avoid a template is the wrong way round.
     *
     * @throws BadRequestHttpException if the request isn't for JSON, or names no game.
     * @throws \yii\base\Exception if the template can't be rendered.
     */
    public function actionBoard(): Response
    {
        $this->requireAcceptsJson();

        $wadKey = $this->wadKey();
        $plugin = Plugin::getInstance();
        $wads = $plugin->getWads()->getAvailableWads();
        $userId = Craft::$app->getUser()->getId();

        return $this->asJson([
            'html' => $this->getView()->renderTemplate('daemon/_leaderboard.twig', [
                'wad' => $wads[$wadKey],
                'rows' => $plugin->getStats()->getLeaderboard($wadKey, $userId !== null ? (int)$userId : null),
            ]),
        ]);
    }

    /**
     * The game the request is about, checked against the WADs actually available
     * rather than accepted as a string.
     *
     * @throws BadRequestHttpException if the key names no available game.
     */
    private function wadKey(): string
    {
        $key = (string)$this->request->getRequiredParam('wad');
        $wads = Plugin::getInstance()->getWads()->getAvailableWads();

        if (!isset($wads[$key])) {
            throw new BadRequestHttpException("No WAD named '$key' is available.");
        }

        return $key;
    }
}
