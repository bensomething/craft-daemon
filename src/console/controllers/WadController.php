<?php

namespace bensomething\daemon\console\controllers;

use bensomething\daemon\Plugin;
use bensomething\daemon\services\Wads;
use Throwable;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Manages the WAD files the engine loads.
 */
class WadController extends Controller
{
    public $defaultAction = 'list';

    /**
     * Downloads Freedoom and installs it into storage/daemon/.
     *
     * Freedoom is a BSD-licensed, engine-compatible replacement for the id WADs,
     * fetched rather than bundled to keep the Composer package small and free of
     * redistribution questions. The archive is verified against a checksum pinned
     * in the plugin source before anything is written.
     */
    public function actionFreedoom(): int
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
     * Downloads the Doom shareware IWAD and installs it into storage/daemon/.
     *
     * The shareware episode is id's, not free software. Its licence allows the
     * release to be passed around, so this fetches it when you ask rather than
     * shipping it. The WAD is verified against a checksum pinned in the plugin
     * source, taken from an artefact matching the MD5 Debian's game-data-packager
     * publishes.
     */
    public function actionShareware(): int
    {
        $wads = Plugin::getInstance()->getWads();

        $this->stdout('Downloading the Doom shareware IWAD ... ');

        try {
            $path = $wads->fetchShareware();
        } catch (Throwable $e) {
            $this->stdout(PHP_EOL);
            $this->stderr($e->getMessage() . PHP_EOL, Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout('done' . PHP_EOL, Console::FG_GREEN);
        $this->stdout('  ' . $path . PHP_EOL);
        $this->stdout(PHP_EOL);
        $this->stdout('This WAD is id Software\'s and is not covered by this plugin\'s licence.' . PHP_EOL, Console::FG_YELLOW);

        return ExitCode::OK;
    }

    /**
     * Lists the WADs available to the plugin.
     */
    public function actionList(): int
    {
        $wads = Plugin::getInstance()->getWads();
        $available = $wads->getAvailableWads();

        if ($available === []) {
            $this->stdout('No WADs found. Run `craft daemon/wad/freedoom` to install it.' . PHP_EOL, Console::FG_YELLOW);

            return ExitCode::OK;
        }

        // Compared by key rather than by object: getDefaultWad() rescans, so
        // the WAD it returns is never the same instance as the one in the list.
        $default = $wads->getDefaultWad()?->key;

        foreach ($available as $wad) {
            $marker = $wad->key === $default ? ' (default)' : '';
            $this->stdout('  ' . $wad->path . $marker . PHP_EOL);
            $this->stdout('    ' . $wad->name . PHP_EOL, Console::FG_GREY);
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

        foreach ($wads->getSearchDirs() as $dir) {
            $this->stdout('Looking:  ' . $dir . (is_dir($dir) ? '' : ' (does not exist)') . PHP_EOL);
        }

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
