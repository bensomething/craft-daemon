<?php

namespace bensomething\daemon\console\controllers;

use bensomething\daemon\Plugin;
use bensomething\daemon\services\Wads;
use Throwable;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Manages the WAD files Doom loads.
 */
class WadController extends Controller
{
    public $defaultAction = 'list';

    /**
     * Downloads Freedoom and installs it into storage/doom/.
     *
     * Freedoom is a BSD-licensed, engine-compatible replacement for the id
     * WADs. It is fetched rather than bundled so the Composer package stays
     * small and free of redistribution questions.
     *
     * The archive is verified against a checksum pinned in the plugin source
     * before anything is written.
     */
    public function actionFetch(): int
    {
        $wads = Plugin::getInstance()->getWads();

        $this->stdout('Downloading Freedoom ' . Wads::FREEDOOM_VERSION . ' ... ');

        try {
            $written = $wads->fetchFreedoom();
        } catch (Throwable $e) {
            $this->stdout(PHP_EOL);
            $this->stderr($e->getMessage() . PHP_EOL, Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout('done' . PHP_EOL, Console::FG_GREEN);

        foreach ($written as $path) {
            $this->stdout('  ' . $path . PHP_EOL);
        }

        return ExitCode::OK;
    }

    /**
     * Lists the WADs available to the plugin.
     */
    public function actionList(): int
    {
        $wads = Plugin::getInstance()->getWads();
        $stored = $wads->getStoredWads();
        $active = $wads->getWadPath();

        if ($stored === [] && $active === null) {
            $this->stdout('No WADs found. Run `craft daemon/wad/fetch` to install Freedoom.' . PHP_EOL, Console::FG_YELLOW);

            return ExitCode::OK;
        }

        foreach ($stored as $path) {
            $marker = $path === $active ? ' (active)' : '';
            $this->stdout('  ' . $path . $marker . PHP_EOL);
        }

        if ($active !== null && !in_array($active, $stored, true)) {
            $this->stdout('  ' . $active . ' (active, configured path)' . PHP_EOL);
        }

        return ExitCode::OK;
    }

    /**
     * Reports where the plugin is looking and what it found.
     */
    public function actionStatus(): int
    {
        $plugin = Plugin::getInstance();
        $engine = $plugin->getEngine();
        $wads = $plugin->getWads();

        $this->stdout('Storage:  ' . $wads->getStorageDir() . PHP_EOL);
        $this->stdout('WAD:      ' . ($wads->getWadPath() ?? 'none') . PHP_EOL);
        $this->stdout('Engine:   ' . ($engine->isInstalled() ? $engine->getPath() : 'not built') . PHP_EOL);

        $build = $engine->getBuildInfo();

        if ($build !== null) {
            foreach ($build as $key => $value) {
                if (is_scalar($value)) {
                    $this->stdout(sprintf('  %-12s %s' . PHP_EOL, $key . ':', (string)$value));
                }
            }
        }

        return ExitCode::OK;
    }
}
