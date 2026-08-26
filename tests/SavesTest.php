<?php

namespace bensomething\daemon\tests;

use bensomething\daemon\services\Saves;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers the parts of the save service that don't need a booted Craft: the
 * path handling, which is the only thing here a request can influence.
 *
 * Everything else goes through Craft::getAlias(), so it is exercised against a
 * real install rather than here.
 */
class SavesTest extends TestCase
{
    /**
     * Paths the engine really produces, with and without the per-WAD directory
     * PrBoom makes when organize_saves is on.
     */
    public function testAcceptsEnginePaths(): void
    {
        $this->assertSame(
            ['A1B2C3D4E5F60718293A4B5C6D7E8F90', 'prboomx-savegame3'],
            $this->parse('A1B2C3D4E5F60718293A4B5C6D7E8F90/prboomx-savegame3.dsg'),
        );

        // No directory of its own, so the placeholder stands in for one.
        $this->assertSame(['_', 'prboomx-savegame3'], $this->parse('prboomx-savegame3.dsg'));
    }

    /**
     * The engine path arrives over HTTP, so it is the one string here that an
     * attacker chooses. None of these may ever become a path.
     *
     * @dataProvider hostilePaths
     */
    public function testRejectsHostilePaths(string $path): void
    {
        $this->assertNull($this->parse($path));
    }

    /**
     * @return array<string, string[]>
     */
    public static function hostilePaths(): array
    {
        return [
            'traversal' => ['../../../../etc/passwd.dsg'],
            'traversal mid-path' => ['A1B2/../../../evil.dsg'],
            'absolute' => ['/etc/passwd.dsg'],
            'too deep' => ['A1B2/deeper/still/x.dsg'],
            'wrong extension' => ['A1B2/prboomx-savegame3.php'],
            'no extension' => ['A1B2/prboomx-savegame3'],
            'empty' => [''],
            'null byte' => ["A1B2/save\0.dsg"],
            'just the extension' => ['.dsg'],
        ];
    }

    /**
     * @return string[]|null
     */
    private function parse(string $path): ?array
    {
        $method = new ReflectionMethod(Saves::class, 'parseEnginePath');
        $method->setAccessible(true);

        return $method->invoke(new Saves(), $path);
    }
}
