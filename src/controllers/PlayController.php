<?php

namespace bensomething\daemon\controllers;

use bensomething\daemon\Plugin;
use bensomething\daemon\services\Engine;
use bensomething\daemon\web\assets\daemon\DaemonAsset;
use Craft;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class PlayController extends Controller
{
    /**
     * The permission handle, declared on the controller that enforces it so
     * registration, the gate and the nav check can't drift apart.
     */
    public const PERMISSION_PLAY = 'daemon:play';

    /**
     * @throws \yii\web\ForbiddenHttpException if the user can't play.
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(self::PERMISSION_PLAY);

        return true;
    }

    /**
     * The CP section itself.
     *
     * @throws \yii\base\InvalidConfigException if the asset bundle can't be registered.
     */
    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $engine = $plugin->getEngine();
        $wads = $plugin->getWads();

        $view = $this->getView();
        $view->registerAssetBundle(DaemonAsset::class);

        // Ask for each file's published URL rather than a base directory:
        // getPublishedUrl() appends a ?v= cache buster, so appending a filename
        // to a directory URL puts the path after the query string and asks the
        // server for the directory instead.
        $assetManager = Craft::$app->getAssetManager();
        $engineJsUrl = null;
        $engineWasmUrl = null;
        $engineDataUrl = null;

        if ($engine->isInstalled()) {
            $engineJsUrl = $assetManager->getPublishedUrl(DaemonAsset::sourcePath(), true, 'engine/' . Engine::JS_FILE);
            $engineWasmUrl = $assetManager->getPublishedUrl(DaemonAsset::sourcePath(), true, 'engine/' . Engine::WASM_FILE);
            $engineDataUrl = $assetManager->getPublishedUrl(DaemonAsset::sourcePath(), true, 'engine/' . Engine::DATA_FILE);
        }

        // Which game is loaded is a query param rather than stored state, so
        // the URL is the whole answer: shareable, bookmarkable, and the same
        // page on a reload.
        $available = $wads->getAvailableWads();
        $wad = $wads->getRequestedWad($this->request->getQueryParam('wad'));

        return $this->renderTemplate('daemon/play.twig', [
            'engineInstalled' => $engine->isInstalled(),
            'engineJsUrl' => $engineJsUrl ?: null,
            'engineWasmUrl' => $engineWasmUrl ?: null,
            'engineDataUrl' => $engineDataUrl ?: null,
            'wads' => $available,
            'wad' => $wad,
            // The Saves menu only means anything once there is a game to
            // save, only for a real user (the actions behind it store per
            // user id), and only when saves are being kept at all.
            'canSave' => $wad !== null
                && Craft::$app->getUser()->getId() !== null
                && $plugin->getSettings()->autosave,
            'autosave' => $plugin->getSettings()->autosave,
            'pointerLock' => $plugin->getSettings()->pointerLock,
            'canManageSettings' => Craft::$app->getUser()->getIsAdmin(),
        ]);
    }

    /**
     * Streams the selected WAD.
     *
     * WADs live under @storage, so this action is the only way the bytes reach a
     * browser, which keeps the permission check on the content itself. The `wad`
     * param is a key, resolved by looking it up in the list the service builds, so
     * nothing a request sends addresses a file.
     *
     * @throws NotFoundHttpException if no valid WAD is configured.
     */
    public function actionWad(): Response
    {
        $wad = Plugin::getInstance()->getWads()->getRequestedWad($this->request->getQueryParam('wad'));

        if ($wad === null) {
            throw new NotFoundHttpException('No WAD is configured.');
        }

        return $this->response->sendFile($wad->path, basename($wad->path), [
            'inline' => true,
            'mimeType' => 'application/octet-stream',
        ]);
    }
}
