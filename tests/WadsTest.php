<?php

namespace bensomething\doom\tests;

use bensomething\doom\services\Wads;
use PHPUnit\Framework\TestCase;

/**
 * Covers the parts of the WAD service that don't need a booted Craft: the
 * magic-number check every other path depends on.
 */
class WadsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/craft-doom-tests-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ((array)glob($this->dir . '/*') as $file) {
            unlink($file);
        }

        rmdir($this->dir);
    }

    public function testAcceptsIwad(): void
    {
        $path = $this->write('good.wad', 'IWAD' . str_repeat("\0", 60));

        $this->assertTrue((new Wads())->isValidWad($path));
    }

    public function testAcceptsPwad(): void
    {
        $path = $this->write('patch.wad', 'PWAD' . str_repeat("\0", 60));

        $this->assertTrue((new Wads())->isValidWad($path));
    }

    /**
     * The check exists precisely because "it ends in .wad" is not evidence of
     * anything. A renamed zip must not reach the engine.
     */
    public function testRejectsImpostor(): void
    {
        $path = $this->write('liar.wad', "PK\x03\x04" . str_repeat("\0", 60));

        $this->assertFalse((new Wads())->isValidWad($path));
    }

    public function testRejectsTruncatedFile(): void
    {
        $path = $this->write('short.wad', 'IWA');

        $this->assertFalse((new Wads())->isValidWad($path));
    }

    public function testRejectsMissingFile(): void
    {
        $this->assertFalse((new Wads())->isValidWad($this->dir . '/nope.wad'));
    }

    public function testRejectsEmptyPath(): void
    {
        $this->assertFalse((new Wads())->isValidWad(''));
    }

    public function testRejectsDirectory(): void
    {
        $this->assertFalse((new Wads())->isValidWad($this->dir));
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }
}
