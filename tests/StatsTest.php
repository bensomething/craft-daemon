<?php

namespace bensomething\daemon\tests;

use bensomething\daemon\enums\Skill;
use bensomething\daemon\models\LevelBest;
use bensomething\daemon\services\Stats;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers the parts of the stat service that don't need a booted Craft: the
 * levelstat parser, which is the one place a string from the browser turns into
 * a record, and the keying and ordering built on top of it.
 *
 * The board itself reads every player's file through Craft::getAlias() and
 * resolves names through an element query, so it is exercised against a real
 * install rather than here.
 */
class StatsTest extends TestCase
{
    /**
     * A real line, from a single player game.
     *
     * The engine pads every column to the widest value in the table, so the
     * spacing is a property of the whole file rather than of the line. These are
     * as they come out of a table holding one level.
     */
    public function testParsesALine(): void
    {
        $result = (new Stats())->parseLine('MAP01 - 1:23.45 (1:23)  K: 20/20  I: 5/6  S: 2/3', Skill::Hard);

        $this->assertNotNull($result);
        $this->assertSame('MAP01', $result->map);
        $this->assertFalse($result->secretExit);
        $this->assertSame(Skill::Hard, $result->skill);
        $this->assertSame(20, $result->kills);
        $this->assertSame(20, $result->killsOf);
        $this->assertSame(5, $result->items);
        $this->assertSame(6, $result->itemsOf);
        $this->assertSame(2, $result->secrets);
        $this->assertSame(3, $result->secretsOf);
    }

    /**
     * Times are kept in tics, the engine's own unit, so that comparing two runs
     * is exact rather than to the hundredth the engine happened to print.
     *
     * 1:23.46 is tic 2921: a minute is 2100 tics, and the remaining 821 divided
     * by the 35 tics in a second is 23.457.
     */
    public function testKeepsTheTimeInTics(): void
    {
        $result = (new Stats())->parseLine('MAP01 - 1:23.46 (1:23)  K: 0/0  I: 0/0  S: 0/0');

        $this->assertNotNull($result);
        $this->assertSame(2921, $result->time);
        $this->assertSame('1:23.46', Stats::formatTime($result->time));
    }

    /**
     * Nothing the engine prints falls between two tics, because it prints from
     * a tic count. A hand-written time that does is taken to the nearest one
     * rather than refused: 23.45 seconds is not a moment the game has.
     */
    public function testSnapsATimeOffTheTicBoundary(): void
    {
        $result = (new Stats())->parseLine('MAP01 - 1:23.45 (1:23)  K: 0/0  I: 0/0  S: 0/0');

        $this->assertNotNull($result);
        $this->assertSame(2921, $result->time);
    }

    /**
     * Every time the engine can print has to come back as the tic it was printed
     * from, or a record set today is beaten by the same run tomorrow. A tic is
     * 0.0286s and the engine prints two decimals, so the rounding it does is
     * recoverable and no two tics share a printed form.
     */
    public function testTimesSurviveTheRoundTrip(): void
    {
        $stats = new Stats();

        for ($tics = 0; $tics < 2100 * 3; $tics += 7) {
            $line = sprintf('MAP01 - %s (0:00)  K: 0/0  I: 0/0  S: 0/0', Stats::formatTime($tics));

            $result = $stats->parseLine($line);

            $this->assertNotNull($result, $line);
            $this->assertSame($tics, $result->time, $line);
        }
    }

    /**
     * The engine marks a secret exit by sticking an 's' on the end of the map
     * name. It is a different route through the same level, usually a shorter
     * one, so it is kept as a run of its own rather than folded in.
     */
    public function testReadsTheSecretExitMarker(): void
    {
        $result = (new Stats())->parseLine('MAP15s - 2:00.00 (2:00)  K: 1/1  I: 1/1  S: 1/1', Skill::Medium);

        $this->assertNotNull($result);
        $this->assertSame('MAP15', $result->map);
        $this->assertTrue($result->secretExit);
        $this->assertSame('MAP15s.2', $result->getKey());
    }

    /**
     * A level completed by a build that was not printing the skill is kept apart
     * rather than folded in with a guess.
     */
    public function testKeysAnUnknownSkillSeparately(): void
    {
        $stats = new Stats();

        $this->assertSame('E1M1.u', $stats->parseLine($this->line('E1M1'))->getKey());
        $this->assertSame('E1M1.4', $stats->parseLine($this->line('E1M1'), Skill::Nightmare)->getKey());
    }

    /**
     * The engine right-aligns each column to the widest value in the table, so
     * the same level is spaced differently depending on what else has been
     * played. All of these are the same run.
     *
     * @dataProvider spacings
     */
    public function testToleratesTheEnginesPadding(string $line): void
    {
        $result = (new Stats())->parseLine($line);

        $this->assertNotNull($result);
        $this->assertSame(2921, $result->time);
        $this->assertSame(2, $result->secrets);
    }

    /**
     * @return array<string, string[]>
     */
    public static function spacings(): array
    {
        return [
            'unpadded' => ['MAP01 - 1:23.45 (1:23)  K: 20/20  I: 5/6  S: 2/3'],
            'padded numbers' => ['MAP01 -   1:23.45 (  1:23)  K:  20/20   I:   5/6   S:   2/3'],
            'trailing space' => ['MAP01 - 1:23.45 (1:23)  K: 20/20  I: 5/6  S: 2/3   '],
            'carriage return' => ["MAP01 - 1:23.45 (1:23)  K: 20/20  I: 5/6  S: 2/3\r"],
            'wide totals' => ['MAP01 - 1:23.45 (1:23)  K: 20/200   I: 5/6    S: 2/3'],
        ];
    }

    /**
     * The line arrives over HTTP, so it is a string an attacker chooses. None of
     * these may become a record.
     *
     * @dataProvider badLines
     */
    public function testRejectsAnythingElse(string $line): void
    {
        $this->assertNull((new Stats())->parseLine($line));
    }

    /**
     * @return array<string, string[]>
     */
    public static function badLines(): array
    {
        return [
            'empty' => [''],
            'prose' => ['MAP01 was quite hard'],
            'no map' => [' - 1:23.45 (1:23)  K: 20/20  I: 5/6  S: 2/3'],
            'map that is not one' => ['MAPXX - 1:23.45 (1:23)  K: 20/20  I: 5/6  S: 2/3'],
            'map out of shape' => ['MAP0001 - 1:23.45 (1:23)  K: 20/20  I: 5/6  S: 2/3'],
            'path as a map' => ['../../etc - 1:23.45 (1:23)  K: 20/20  I: 5/6  S: 2/3'],
            'missing secrets' => ['MAP01 - 1:23.45 (1:23)  K: 20/20  I: 5/6'],
            'negative time' => ['MAP01 - -1:23.45 (1:23)  K: 20/20  I: 5/6  S: 2/3'],
            'two lines at once' => ["MAP01 - 1:23.45 (1:23)  K: 0/0  I: 0/0  S: 0/0\nMAP02 - 1:00.00 (1:00)  K: 0/0  I: 0/0  S: 0/0"],
            // A net game writes the per-player breakdown in brackets after each
            // figure. This build cannot net-play, so it did not come from it.
            'multiplayer' => ['MAP01 - 1:23.45 (1:23)  K: 20/20 (10+10)  I: 5/6 (2+3)  S: 2/3 (1+1)'],
        ];
    }

    /**
     * Longer than any line the engine produces, so it is refused before the
     * pattern is run over it.
     */
    public function testRejectsAnOverlongLine(): void
    {
        $line = 'MAP01 - 1:23.45 (1:23)  K: 20/20  I: 5/6  S: 2/3' . str_repeat(' ', Stats::MAX_LINE_BYTES);

        $this->assertNull((new Stats())->parseLine($line));
    }

    /**
     * Level order: episode, then map, then the normal exit before the secret
     * one, then hardest first, with an unknown skill last.
     */
    public function testOrdersRuns(): void
    {
        $keys = ['MAP15s.2', 'E1M1.3', 'MAP02.u', 'MAP02.4', 'MAP15.2', 'E1M1.0'];
        $bests = array_map(fn(string $key) => $this->best($key), $keys);

        usort($bests, fn(LevelBest $a, LevelBest $b) => $a->getOrder() <=> $b->getOrder());

        $this->assertSame(
            ['MAP02.4', 'MAP02.u', 'MAP15.2', 'MAP15s.2', 'E1M1.3', 'E1M1.0'],
            array_map(fn(LevelBest $b) => $b->key, $bests),
        );
    }

    /**
     * Gathering the players on the board.
     *
     * Keyed by run, so the keys are strings like "MAP01.3". Written as a loop
     * for that reason: the version that unpacked array_keys() across
     * array_merge() took those keys as named arguments and threw, but only once
     * somebody had finished a level, so an empty board looked perfectly well.
     */
    public function testCollectsEveryPlayerOnce(): void
    {
        $byLevel = [
            'MAP01.3' => [7 => $this->best('MAP01.3'), 9 => $this->best('MAP01.3')],
            'MAP02.3' => [9 => $this->best('MAP02.3')],
            'MAP15s.4' => [12 => $this->best('MAP15s.4')],
        ];

        $this->assertSame([7, 9, 12], $this->call('collectUserIds', $byLevel));
    }

    /**
     * Lowest wins on time, highest on a count, and a tie goes to whoever got
     * there first while still being counted as shared.
     */
    public function testPicksTheLeader(): void
    {
        $names = [7 => 'Ada', 9 => 'Grace'];

        $faster = $this->call('lead', [
            7 => $this->best('MAP01.3', time: 4000, timeAt: 100),
            9 => $this->best('MAP01.3', time: 3000, timeAt: 200),
        ], 'time', $names, 7, true);

        $this->assertSame(9, $faster->userId);
        $this->assertSame('Grace', $faster->name);
        $this->assertFalse($faster->isCurrentUser);
        $this->assertSame(0, $faster->sharedWith);
        $this->assertSame('1:25.71', $faster->display);

        $most = $this->call('lead', [
            7 => $this->best('MAP01.3', secrets: 1, secretsOf: 3),
            9 => $this->best('MAP01.3', secrets: 3, secretsOf: 3),
        ], 'secrets', $names, 9, false);

        $this->assertSame(9, $most->userId);
        $this->assertTrue($most->isCurrentUser);
        $this->assertSame('3/3', $most->display);
    }

    /** A matched figure stays with whoever set it first, and says it is shared. */
    public function testTiesGoToWhoeverWasFirst(): void
    {
        $leader = $this->call('lead', [
            7 => $this->best('MAP01.3', secrets: 3, secretsOf: 3, secretsAt: 500),
            9 => $this->best('MAP01.3', secrets: 3, secretsOf: 3, secretsAt: 100),
        ], 'secrets', [7 => 'Ada', 9 => 'Grace'], null, false);

        $this->assertSame(9, $leader->userId);
        $this->assertSame(1, $leader->sharedWith);
    }

    /** A user Craft no longer has is left unnamed rather than named by id. */
    public function testLeavesADepartedPlayerUnnamed(): void
    {
        $leader = $this->call('lead', [
            7 => $this->best('MAP01.3'),
        ], 'time', [], null, true);

        $this->assertSame('', $leader->name);
    }

    /**
     * The browser posts what the controller reads.
     *
     * The two halves of this live in different languages in different files,
     * and nothing but this makes them agree. When they stopped agreeing, every
     * level was parsed, sent and refused, the browser logged a console warning
     * nobody was looking at, and the board stayed empty while working perfectly.
     */
    public function testTheBrowserPostsWhatTheControllerReads(): void
    {
        $root = dirname(__DIR__);
        $host = (string)file_get_contents($root . '/src/web/assets/daemon/dist/daemon-host.js');
        $controller = (string)file_get_contents($root . '/src/controllers/StatsController.php');

        $this->assertStringContainsString('levels: levels', $host);
        $this->assertStringContainsString("getBodyParam('levels')", $controller);

        // Both actions the host names have to exist on the controller, spelled
        // the way Yii will resolve them.
        $this->assertStringContainsString("record: 'daemon/stats/record'", $this->play($root));
        $this->assertStringContainsString('public function actionRecord()', $controller);
        $this->assertStringContainsString("board: 'daemon/stats/board'", $this->play($root));
        $this->assertStringContainsString('public function actionBoard()', $controller);
    }

    /** The template that hands the host its action names. */
    private function play(string $root): string
    {
        return (string)file_get_contents($root . '/src/templates/play.twig');
    }

    /** A levelstat line for one map, with everything else at zero. */
    private function line(string $map): string
    {
        return $map . ' - 1:23.45 (1:23)  K: 0/0  I: 0/0  S: 0/0';
    }

    /**
     * A LevelBest from a stored key, with only the figures a test cares about
     * set. Never a secret exit key with a label to render: getLabel() reaches
     * for Craft::t() for those, and nothing here has booted Craft.
     */
    private function best(
        string $key,
        int $time = 0,
        int $timeAt = 0,
        int $secrets = 0,
        int $secretsOf = 0,
        int $secretsAt = 0,
    ): LevelBest {
        preg_match('/^(MAP\d+|E\dM\d)(s?)\.(\d|u)$/', $key, $m);

        return new LevelBest(
            $key,
            $m[1],
            $m[2] !== '',
            Skill::fromKey($m[3]),
            $time,
            $timeAt,
            0, 0, 0,
            0, 0, 0,
            $secrets,
            $secretsOf,
            $secretsAt,
            1,
            0,
        );
    }

    /** Calls a private method on a fresh service. */
    private function call(string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod(Stats::class, $method))->invoke(new Stats(), ...$args);
    }
}
