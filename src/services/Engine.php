<?php

namespace bensomething\daemon\services;

use craft\helpers\Json;
use yii\base\Component;

/**
 * Everything to do with the compiled engine artefacts.
 *
 * The artefacts are not fetched at runtime and are not shipped by any upstream
 * release: they are built once with `bin/build-engine.sh` and committed. This
 * service exists so the CP can tell an admin, plainly, that the plugin is
 * installed but the engine isn't.
 */
class Engine extends Component
{
    /**
     * The Emscripten glue script.
     */
    public const JS_FILE = 'index.js';

    /**
     * The WebAssembly module. Fetched to an ArrayBuffer by the host script
     * rather than streamed, because `cpresources` is served by the site's own
     * web server and application/wasm is not reliably in its MIME map.
     */
    public const WASM_FILE = 'index.wasm';

    /**
     * Emscripten's preloaded filesystem. Carries the engine's own resource WAD
     * (prboomx.wad), which is mandatory, and no game content: the admin's IWAD
     * is written in at runtime instead, so one build serves every WAD.
     */
    public const DATA_FILE = 'index.data';

    /**
     * Provenance written by bin/build-engine.sh. Not required for the engine to
     * run, but its absence means someone dropped files in by hand.
     */
    public const BUILD_FILE = 'BUILD.json';

    /**
     * Absolute path to the directory the artefacts live in.
     */
    public function getPath(): string
    {
        return dirname(__DIR__) . '/web/assets/daemon/dist/engine';
    }

    /**
     * Whether both artefacts are present. False is the expected state on a
     * fresh clone, and the CP screen renders a build prompt instead of a canvas.
     */
    public function isInstalled(): bool
    {
        return is_file($this->getPath() . '/' . self::JS_FILE)
            && is_file($this->getPath() . '/' . self::WASM_FILE)
            && is_file($this->getPath() . '/' . self::DATA_FILE);
    }

    /**
     * Build provenance (upstream repo, commit, Emscripten version, build date),
     * or null when BUILD.json is missing or unreadable.
     */
    public function getBuildInfo(): ?array
    {
        $path = $this->getPath() . '/' . self::BUILD_FILE;

        if (!is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $decoded = Json::decodeIfJson($contents);

        return is_array($decoded) ? $decoded : null;
    }
}
