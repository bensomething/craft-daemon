<?php

namespace bensomething\daemon\controllers;

use bensomething\daemon\Plugin;
use Craft;
use craft\web\Controller;
use craft\web\View;
use Throwable;
use yii\web\Response;

/**
 * Fetching Freedoom from the settings screen, with progress.
 *
 * The console command does the same job. This exists because a settings screen
 * that says "run this in a terminal" is a settings screen that hasn't finished.
 */
class WadController extends Controller
{
    /**
     * Where download progress is parked for the polling action to read.
     *
     * Progress can't be returned from the request doing the work, so it goes
     * through the cache. It's a singleton because this is an admin-only action
     * on a novelty plugin; two admins racing to download the same file is not a
     * scenario worth engineering around.
     */
    private const PROGRESS_KEY = 'daemon.wad.progress';

    /**
     * Writing progress on every Guzzle callback would hammer the cache for no
     * visible benefit, so only whole-percent changes are stored.
     */
    private const PROGRESS_STEP = 1;

    /**
     * @throws \yii\web\ForbiddenHttpException if the user isn't an admin.
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // false: downloading a WAD writes to storage, not to project config, so
        // it isn't an admin *change* and shouldn't be blocked by
        // allowAdminChanges.
        $this->requireAdmin(false);

        return true;
    }

    /**
     * Downloads Freedoom, reporting progress as it goes.
     *
     * @throws \yii\web\BadRequestHttpException if the request isn't a POST.
     */
    public function actionFetch(): Response
    {
        return $this->download(
            fn(callable $onProgress) => Plugin::getInstance()->getWads()->fetchFreedoom($onProgress),
            Craft::t('daemon', 'Freedoom installed.'),
        );
    }

    /**
     * Downloads the Doom shareware IWAD, reporting progress as it goes.
     *
     * @throws \yii\web\BadRequestHttpException if the request isn't a POST.
     */
    public function actionShareware(): Response
    {
        return $this->download(
            fn(callable $onProgress) => [Plugin::getInstance()->getWads()->fetchShareware($onProgress)],
            Craft::t('daemon', 'The Doom shareware IWAD is id Software\'s, and is not covered by this plugin\'s licence.'),
        );
    }

    /**
     * Runs one download, publishing progress for actionProgress() to read.
     *
     * @param callable $fetch Given the progress callback, does the work and
     * returns the paths written.
     * @param string $message What to tell the browser on success.
     * @throws \yii\web\BadRequestHttpException if the request isn't a POST.
     */
    private function download(callable $fetch, string $message): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $cache = Craft::$app->getCache();

        $this->setProgress(0, 0, 'downloading');

        // PHP holds an exclusive lock on the session file for the life of a
        // request, so actionProgress() would queue behind this one and the bar
        // would sit at zero until the download finished. Closing the session
        // releases the lock; the identity is already resolved by this point.
        Craft::$app->getSession()->close();

        $lastPercent = -1;

        try {
            $written = $fetch(
                function(int $downloaded, int $total) use (&$lastPercent) {
                    if ($total <= 0) {
                        return;
                    }

                    $percent = (int)floor($downloaded / $total * 100);

                    if ($percent - $lastPercent < self::PROGRESS_STEP) {
                        return;
                    }

                    $lastPercent = $percent;
                    $this->setProgress($downloaded, $total, 'downloading');
                },
            );
        } catch (Throwable $e) {
            $cache->delete(self::PROGRESS_KEY);
            Craft::error("WAD download failed: {$e->getMessage()}", __METHOD__);

            // Plain JSON rather than asFailure()/asSuccess(): those set a
            // session flash, which would re-open the session we just closed and
            // then show a second notice after the page reloads. The screen
            // announces the outcome itself.
            $this->response->setStatusCode(500);

            return $this->asJson(['message' => $e->getMessage()]);
        }

        $cache->delete(self::PROGRESS_KEY);

        return $this->asJson([
            'message' => $message,
            'wads' => $written,
        ]);
    }

    /**
     * Re-renders the WAD list for the settings screen.
     *
     * So a finished download can show its result without reloading the page.
     * The settings screen is a full page form carrying data-confirm-unload, so
     * a reload part way through editing either discards names the admin has
     * typed or stops to ask them about it, and neither is a reasonable answer
     * to pressing Download.
     *
     * The `settings` namespace is not decoration. Craft renders plugin
     * settings inside View::namespaceInputs() with that namespace, so the
     * inputs on the page are named settings[wadNames][...]. Rendering the same
     * template on its own produces wadNames[...] instead, which posts to
     * nothing and loses every name on the next save.
     *
     * @throws \yii\base\Exception if the template can't be rendered.
     * @throws \yii\web\BadRequestHttpException if the request doesn't accept JSON.
     * @throws \Twig\Error\LoaderError if the template is missing.
     * @throws \Twig\Error\RuntimeError if rendering it fails.
     * @throws \Twig\Error\SyntaxError if it doesn't parse.
     */
    public function actionList(): Response
    {
        $this->requireAcceptsJson();

        $view = Craft::$app->getView();
        $plugin = Plugin::getInstance();

        $html = $view->namespaceInputs(fn() => $view->renderTemplate('daemon/_wads', [
            'available' => $plugin->getWads()->getAvailableWads(),
            'settings' => $plugin->getSettings(),
        ], View::TEMPLATE_MODE_CP), 'settings');

        return $this->asJson(['html' => $html]);
    }

    /**
     * Reports how far the download has got. Polled by the settings screen.
     */
    public function actionProgress(): Response
    {
        $this->requireAcceptsJson();

        $progress = Craft::$app->getCache()->get(self::PROGRESS_KEY);

        if (!is_array($progress)) {
            return $this->asJson(['status' => 'idle']);
        }

        return $this->asJson($progress);
    }

    private function setProgress(int $downloaded, int $total, string $status): void
    {
        Craft::$app->getCache()->set(self::PROGRESS_KEY, [
            'status' => $status,
            'downloaded' => $downloaded,
            'total' => $total,
        ], 600);
    }
}
