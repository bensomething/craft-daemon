<?php

namespace bensomething\daemon\controllers;

use bensomething\daemon\models\Save;
use bensomething\daemon\Plugin;
use bensomething\daemon\services\Saves;
use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craft\web\Controller;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\HttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;
use ZipArchive;

/**
 * Reading and writing savegames on behalf of the browser.
 *
 * Everything here is scoped to the logged-in user. A save is addressed by an id
 * the service issued, never by a path, and the user id comes from the session
 * rather than the request, so no id can reach another player's saves.
 */
class SaveController extends Controller
{
    /**
     * The largest archive built in one go. The newest save of each slot of each
     * game, which in practice is a few megabytes.
     */
    private const MAX_ARCHIVE_BYTES = 67108864;

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
     * @throws BadRequestHttpException if the request isn't for JSON, or names no game.
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
     * The newest version of each save, with its bytes, for a page that has just
     * loaded and wants the player's slots back.
     *
     * @throws BadRequestHttpException if the request isn't for JSON, or names no game.
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
     * One stored save, with its bytes. What picking an older version calls.
     *
     * @throws BadRequestHttpException if the request isn't for JSON, names no game,
     * or names no save.
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
     * @throws BadRequestHttpException if the request isn't a JSON POST, or names no game.
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
     * Sends one stored save to the browser as a file.
     *
     * Named as the engine named it, so the download drops straight into a
     * desktop PrBoom's save directory. Two versions of the same slot therefore
     * share a filename, and the browser is left to do what it does about that:
     * the alternative is a name the engine would not load.
     *
     * @throws BadRequestHttpException if the request names no game or no save.
     * @throws NotFoundHttpException if the save is gone.
     */
    public function actionDownload(): Response
    {
        $wadKey = $this->wadKey();
        $id = (string)$this->request->getRequiredParam('id');
        $userId = $this->userId();
        $saves = $this->getSaves();
        $all = $saves->getSaves($userId, $wadKey);

        if (!isset($all[$id])) {
            throw new NotFoundHttpException('That save is gone.');
        }

        $contents = $saves->read($userId, $wadKey, $id);

        if ($contents === null) {
            throw new NotFoundHttpException('That save could not be read.');
        }

        return $this->response->sendContentAsFile($contents, basename($all[$id]->enginePath), [
            'mimeType' => 'application/octet-stream',
            'inline' => false,
        ]);
    }

    /**
     * Sends every game's newest save, as a zip.
     *
     * The newest of each slot rather than every version: this is the archive
     * you would carry to another machine, and the engine only ever loads the
     * one in the slot. Older versions are a download at a time, from the same
     * screen. Laid out as <game>/<the engine's own path>, so unzipping over a
     * PrBoom save directory puts everything where it expects.
     *
     * @throws NotFoundHttpException if the user has no saves at all.
     * @throws HttpException if there is more to archive than the ceiling allows.
     * @throws ServerErrorHttpException if the archive can't be opened.
     * @throws \yii\base\Exception if the temporary file can't be written.
     */
    public function actionDownloadAll(): Response
    {
        $userId = $this->userId();
        $saves = $this->getSaves();
        $plugin = Plugin::getInstance();

        $path = Craft::$app->getPath()->getTempPath() . '/daemon-saves-' . StringHelper::UUID() . '.zip';
        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new ServerErrorHttpException('Could not build the archive.');
        }

        $bytes = 0;
        $count = 0;

        foreach ($plugin->getWads()->getAvailableWads() as $wadKey => $wad) {
            foreach ($saves->getLatestSaves($userId, $wadKey) as $save) {
                $contents = $saves->read($userId, $wadKey, $save->id);

                if ($contents === null) {
                    continue;
                }

                $bytes += strlen($contents);

                // A ceiling rather than a promise: the newest of each slot
                // cannot realistically reach this, and an archive built without
                // one is a way to ask a server to hold an unbounded string.
                //
                // Thrown rather than returned through asFailure(), which hands
                // back null for anything that does not accept JSON. This action
                // is reached by following a link, so the browser asks for HTML,
                // and a null from a method declared to return a Response is a
                // TypeError rather than the message it was meant to be.
                if ($bytes > self::MAX_ARCHIVE_BYTES) {
                    $zip->close();
                    FileHelper::unlink($path);

                    throw new HttpException(413, Craft::t('daemon', 'There are too many saves to archive at once.'));
                }

                $zip->addFromString($wadKey . '/' . $save->enginePath, $contents);
                $count++;
            }
        }

        $zip->close();

        if ($count === 0) {
            FileHelper::unlink($path);

            throw new NotFoundHttpException('There are no saves to download.');
        }

        // Craft sweeps @runtime/temp, but a file the size of a savegame archive
        // should not wait for that.
        $this->response->on(Response::EVENT_AFTER_SEND, static function() use ($path) {
            FileHelper::unlink($path);
        });

        return $this->response->sendFile($path, 'daemon-saves.zip', [
            'mimeType' => 'application/zip',
            'inline' => false,
        ]);
    }

    /**
     * The save service.
     */
    private function getSaves(): Saves
    {
        return Plugin::getInstance()->getSaves();
    }

    /**
     * The logged-in user's id. beforeAction() has already refused anyone who isn't
     * logged in, so this cannot be null.
     */
    private function userId(): int
    {
        return (int)Craft::$app->getUser()->getId();
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
