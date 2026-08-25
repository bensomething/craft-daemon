<?php

namespace bensomething\doom\tests;

use bensomething\doom\services\Engine;
use PHPUnit\Framework\TestCase;

class EngineTest extends TestCase
{
    /**
     * The engine directory is inside the plugin, so this asserts the service is
     * pointed at a real place rather than at a path that only exists in a
     * particular install layout.
     */
    public function testPathResolvesInsideThePlugin(): void
    {
        $path = (new Engine())->getPath();

        $this->assertDirectoryExists($path);
        $this->assertStringEndsWith('src/web/assets/doom/dist/engine', $path);
    }

    /**
     * isInstalled() is what the CP screen branches on, so it has to mean "both
     * artefacts are here", not "the directory exists". A fresh clone has the
     * directory and its README and nothing else.
     */
    public function testReportsNotInstalledWithoutBothArtefacts(): void
    {
        $engine = new Engine();
        $path = $engine->getPath();

        $hasJs = is_file($path . '/' . Engine::JS_FILE);
        $hasWasm = is_file($path . '/' . Engine::WASM_FILE);

        $this->assertSame($hasJs && $hasWasm, $engine->isInstalled());
    }

    public function testBuildInfoIsNullWhenAbsent(): void
    {
        $engine = new Engine();

        if (is_file($engine->getPath() . '/' . Engine::BUILD_FILE)) {
            $this->assertIsArray($engine->getBuildInfo());
            return;
        }

        $this->assertNull($engine->getBuildInfo());
    }
}
