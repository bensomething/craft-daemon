<?php

namespace bensomething\doom\controllers;

use bensomething\doom\Plugin;
use bensomething\doom\services\Engine;
use bensomething\doom\web\assets\doom\DoomAsset;
use Craft;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class PlayController extends Controller
{
    /**
     * The permission handle. Declared here, on the controller that enforces it,
     * so registration, the gate and the nav check can't drift apart.
     */
    public const PERMISSION_PLAY = 'doom:play';

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
        $view->registerAssetBundle(DoomAsset::class);

        // The engine artefacts are published alongside the host script but
        // loaded by URL at runtime, so the template needs their public base.
        $engineUrl = $engine->isInstalled()
            ? Craft::$app->getAssetManager()->getPublishedUrl(
                DoomAsset::sourcePath(),
                true,
                'engine',
            )
            : null;

        return $this->renderTemplate('doom/play.twig', [
            'engineInstalled' => $engine->isInstalled(),
            'engineUrl' => $engineUrl ?: null,
            'engineJs' => Engine::JS_FILE,
            'engineWasm' => Engine::WASM_FILE,
            'buildInfo' => $engine->getBuildInfo(),
            'wadPath' => $wads->getWadPath(),
            'pointerLock' => $plugin->getSettings()->pointerLock,
            'canManageSettings' => Craft::$app->getUser()->getIsAdmin(),
        ]);
    }

    /**
     * Streams the configured WAD.
     *
     * WADs live under @storage, not the web root, so this action is the only
     * way the bytes reach a browser. That keeps the permission check on the
     * content itself rather than on the page that happens to link to it.
     *
     * @throws NotFoundHttpException if no valid WAD is configured.
     */
    public function actionWad(): Response
    {
        $path = Plugin::getInstance()->getWads()->getWadPath();

        if ($path === null) {
            throw new NotFoundHttpException('No WAD is configured.');
        }

        return $this->response->sendFile($path, basename($path), [
            'inline' => true,
            'mimeType' => 'application/octet-stream',
        ]);
    }
}
