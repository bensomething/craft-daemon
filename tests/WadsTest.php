<?php

namespace bensomething\daemon\tests;

use bensomething\daemon\services\Wads;
use PHPUnit\Framework\TestCase;

/**
 * Covers the parts of the WAD service that don't need a booted Craft: the
 * magic-number check every other path depends on, and the naming the selector
 * is built from.
 */
class WadsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/craft-daemon-tests-' . bin2hex(random_bytes(6));
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

    public function testNamesKnownIwads(): void
    {
        $this->assertSame('Freedoom: Phase 1', Wads::nameFor('/wads/freedoom1.wad'));
        $this->assertSame('Doom II', Wads::nameFor('/wads/DOOM2.WAD'));
    }

    /**
     * Guessing a title from an unknown filename would be inventing one, so an
     * unrecognised WAD is called what it is called.
     */
    public function testNamesUnknownWadsAfterTheirFile(): void
    {
        $this->assertSame('sunlust', Wads::nameFor('/wads/sunlust.wad'));
    }

    /**
     * The menu tells games apart by name, so the icon is the same for all of
     * them. This is here to catch a rename of the icon rather than a change of
     * mind about it: an icon name Craft doesn't have throws when the menu
     * renders, not when it is set.
     */
    public function testUsesAKnownCraftIcon(): void
    {
        $this->assertFileExists(
            dirname(__DIR__) . '/vendor/craftcms/cms/src/icons/solid/' . Wads::ICON . '.svg',
        );
    }

    /**
     * Keys end up in a query string, so anything that would need escaping has
     * to be gone by the time one is built.
     */
    public function testBuildsUrlSafeKeys(): void
    {
        $this->assertSame('freedoom1', Wads::keyFor('/wads/freedoom1.wad'));
        $this->assertSame('doom2', Wads::keyFor('/wads/DOOM2.WAD'));
        $this->assertSame('back-to-saturn-x-e1', Wads::keyFor('/wads/Back to Saturn X (E1).wad'));
    }

    /**
     * A filename with nothing usable in it still has to produce a key, because
     * a WAD with no key is a WAD that can't be selected.
     */
    public function testFallsBackWhenAFilenameHasNoUsableCharacters(): void
    {
        $this->assertSame('wad', Wads::keyFor('/wads/___.wad'));
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }
}
