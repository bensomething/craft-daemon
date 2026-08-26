<?php

namespace bensomething\daemon\controllers;

use bensomething\daemon\models\Save;
use bensomething\daemon\Plugin;
use bensomething\daemon\services\Saves;
use Craft;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Reading and writing savegames on behalf of the browser.
 *
 * Everything here is scoped to the logged-in user. A save is not addressed by
 * a path, only by an id the service issued, and the user id comes from the
 * session rather than the request, so there is no id one player can send to
 * reach another's saves.
 */
class SaveController extends Controller
{
    /**
     * @throws \yii\web\ForbiddenHttpException if the user can't play.
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(PlayController::PERMISSION_PLAY);

        return true;
    }

    /**
     * Every version of every save for one game, for the menu.
     *
     * @throws BadRequestHttpException if the request isn't for JSON, or names
     * no game.
     */
    public function actionList(): Response
    {
        $this->requireAcceptsJson();

        $wadKey = $this->wadKey();
        $saves = $this->getSaves()->getSaves($this->userId(), $wadKey);

        return $this->asJson([
            'saves' => array_map(fn(Save $save) => $this->toArray($save), array_values($saves)),
        ]);
    }

    /**
     * The newest version of each save, with its bytes, for a page that has
     * just loaded and wants the player's slots back.
     *
     * @throws BadRequestHttpException if the request isn't for JSON, or names
     * no game.
     */
    public function actionRestore(): Response
    {
        $this->requireAcceptsJson();

        $wadKey = $this->wadKey();
        $userId = $this->userId();
        $saves = $this->getSaves();
        $out = [];

        foreach ($saves->getLatestSaves($userId, $wadKey) as $save) {
            $contents = $saves->read($userId, $wadKey, $save->id);

            if ($contents !== null) {
                $out[] = $this->toArray($save) + ['data' => base64_encode($contents)];
            }
        }

        return $this->asJson(['saves' => $out]);
    }

    /**
     * One stored save, with its bytes. This is what picking an older version
     * out of the menu calls.
     *
     * @throws BadRequestHttpException if the request isn't for JSON, names no
     * game, or names no save.
     */
    public function actionRead(): Response
    {
        $this->requireAcceptsJson();

        $wadKey = $this->wadKey();
        $id = (string)$this->request->getRequiredParam('id');
        $userId = $this->userId();
        $saves = $this->getSaves();
        $all = $saves->getSaves($userId, $wadKey);

        if (!isset($all[$id])) {
            return $this->asFailure(Craft::t('daemon', 'That save is gone.'));
        }

        $contents = $saves->read($userId, $wadKey, $id);

        if ($contents === null) {
            return $this->asFailure(Craft::t('daemon', 'That save could not be read.'));
        }

        return $this->asJson([
            'save' => $this->toArray($all[$id]) + ['data' => base64_encode($contents)],
        ]);
    }

    /**
     * Stores savegames the browser has just pulled out of the engine.
     *
     * @throws BadRequestHttpException if the request isn't a JSON POST, or
     * names no game.
     */
    public function actionUpload(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $wadKey = $this->wadKey();
        $userId = $this->userId();
        $saves = $this->getSaves();
        $posted = $this->request->getBodyParam('saves');

        if (!is_array($posted)) {
            throw new BadRequestHttpException('No saves were posted.');
        }

        $stored = [];

        foreach ($posted as $item) {
            if (!is_array($item) || !isset($item['path'], $item['data'])) {
                continue;
            }

            // strict: base64_decode() is otherwise happy to skip whatever it
            // doesn't recognise and hand back a shorter, corrupt savegame.
            $contents = base64_decode((string)$item['data'], true);

            if ($contents === false) {
                continue;
            }

            try {
                $stored[] = $this->toArray($saves->store($userId, $wadKey, (string)$item['path'], $contents));
            } catch (Throwable $e) {
                Craft::warning("Could not store a savegame: {$e->getMessage()}", __METHOD__);
            }
        }

        if ($stored === [] && $posted !== []) {
            return $this->asFailure(Craft::t('daemon', 'Nothing could be saved.'));
        }

        return $this->asSuccess(
            Craft::t('daemon', '{count, plural, =0{Nothing to save} =1{1 save kept} other{# saves kept}}', [
                'count' => count($stored),
            ]),
            ['saves' => $stored],
        );
    }

    /**
     * The save service.
     */
    private function getSaves(): Saves
    {
        return Plugin::getInstance()->getSaves();
    }

    /**
     * The logged-in user's id. beforeAction() has already refused anyone who
     * isn't logged in, so this cannot be null by the time it is called.
     */
    private function userId(): int
    {
        return (int)Craft::$app->getUser()->getId();
    }

    /**
     * The game the request is about.
     *
     * Checked against the WADs actually available rather than accepted as a
     * string, so a key can only ever name a game this install has.
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

    /**
     * One save, as the browser wants it.
     *
     * @return array<string, mixed>
     */
    private function toArray(Save $save): array
    {
        return [
            'id' => $save->id,
            'path' => $save->enginePath,
            'label' => $save->getLabel(),
            'slot' => $save->slot,
            'size' => $save->size,
            'savedAt' => $save->savedAt,
            'timestamp' => Craft::$app->getFormatter()->asTimestamp(
                DateTimeHelper::toDateTime($save->savedAt),
                'short',
                true,
            ),
        ];
    }
}
