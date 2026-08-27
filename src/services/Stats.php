<?php

namespace bensomething\daemon\services;

use bensomething\daemon\enums\Skill;
use bensomething\daemon\helpers\UserStorage;
use bensomething\daemon\models\Leader;
use bensomething\daemon\models\LevelBest;
use bensomething\daemon\models\LevelResult;
use Craft;
use craft\elements\User;
use craft\helpers\FileHelper;
use RuntimeException;
use yii\base\Component;

/**
 * Keeping level stats, and building a board out of them.
 *
 * PrBoom+ has carried a speedrunner's stat table since e6y's day: pass
 * -levelstat and it rewrites levelstat.txt every time a level is exited, one
 * line per level completed, with the time to a hundredth of a second and the
 * kills, items and secrets against their totals. That file is the whole data
 * source. Nothing is read out of the engine's memory, because nothing can be:
 * the build exports main() and the filesystem, and no game symbols.
 *
 * The one thing levelstat.txt does not carry is the skill, which matters more
 * than anything else on it: a time from I'm Too Young To Die is not a slower
 * Ultra-Violence time, it is a different sport. So the build prints the skill
 * at every level exit (see bin/build-engine.sh) and the host pairs it with the
 * line, and a level is held once per skill rather than once. Records made
 * before that patch have no skill and are kept apart under 'u' rather than
 * folded in with a guess.
 *
 * What it cannot know is whether anyone was cheating. This is a browser
 * reporting its own numbers, and IDCLEV and IDDQD are two of the best known
 * strings in software. Treat the board as a conversation piece.
 */
class Stats extends Component
{
    /** Tics per second. TICRATE in the engine, and the unit times are kept in. */
    public const TICRATE = 35;

    /**
     * The most rows held for one player and one game. A Doom II WAD has 32 maps
     * and a handful of secret exits, and a row exists per skill played, so this
     * is loose enough never to be reached in practice and tight enough that a
     * client cannot grow the file for ever.
     */
    public const MAX_LEVELS = 256;

    /**
     * The most lines accepted in one request. The browser sends only the lines
     * it has not sent before, so a normal request carries one.
     */
    public const MAX_LINES = 64;

    /** The longest line parsed. A real one runs to about 50 characters. */
    public const MAX_LINE_BYTES = 256;

    /** The largest stat file read back. Far above what MAX_LEVELS can produce. */
    private const MAX_FILE_BYTES = 262144;

    /** Where stats are kept. Under @storage, like the WADs and the savegames. */
    public function getStorageDir(): string
    {
        return Craft::getAlias('@storage/daemon/stats');
    }

    /**
     * Deletes the stats of users who no longer exist.
     *
     * @return int How many users' stats were removed.
     * @throws \yii\base\ErrorException if a directory can't be deleted.
     */
    public function deleteOrphaned(): int
    {
        return UserStorage::sweepOrphans($this->getStorageDir());
    }

    /**
     * Reads one line of levelstat.txt.
     *
     * The engine builds the format string at runtime, padding every column to
     * the widest value in the table, so the same line is spaced differently
     * depending on what else is in the file. Hence \s+ throughout rather than
     * fixed columns.
     *
     * A line for more than one player carries the per-player breakdown in
     * brackets after each figure. This build cannot net-play, so such a line
     * did not come from it and is refused rather than guessed at.
     *
     * @param Skill|null $skill What the host read off stdout for this level.
     * @return LevelResult|null null when the line is not one the engine wrote.
     */
    public function parseLine(string $line, ?Skill $skill = null): ?LevelResult
    {
        if (strlen($line) > self::MAX_LINE_BYTES) {
            return null;
        }

        $matched = preg_match(
            '/^(\w+)\s+-\s+(\d+):(\d+(?:\.\d+)?)\s+\(\s*\d+:\d+\)\s+' .
            'K:\s*(\d+)\/(\d+)\s+I:\s*(\d+)\/(\d+)\s+S:\s*(\d+)\/(\d+)\s*$/',
            trim($line),
            $m,
        );

        if (!$matched) {
            return null;
        }

        if (!preg_match('/^(MAP\d{1,3}|E\d{1,2}M\d{1,2})(s?)$/i', $m[1], $map)) {
            return null;
        }

        // Minutes and seconds, back to the tics they were printed from. The
        // seconds carry two decimals and a tic is 0.028s, so the rounding is
        // lossless in this direction as well as the one the engine went.
        $tics = ((int)$m[2] * 60 * self::TICRATE) + (int)round((float)$m[3] * self::TICRATE);

        return new LevelResult(
            strtoupper($map[1]),
            $map[2] !== '',
            $tics,
            (int)$m[4],
            (int)$m[5],
            (int)$m[6],
            (int)$m[7],
            (int)$m[8],
            (int)$m[9],
            $skill,
        );
    }

    /**
     * Folds newly completed levels into one player's record for one game.
     *
     * Each figure is kept separately and only ever improves, so sending the same
     * line twice changes nothing except the play count, which is why the browser
     * sends only lines it has not sent before.
     *
     * @param array<int, mixed> $levels One entry per completed level, each an
     * array with a `line` from levelstat.txt and, when the engine printed one, a
     * `skill`.
     * @return int How many were understood and applied.
     * @throws RuntimeException if the game key is not one that could have come
     * from the WAD service.
     * @throws \yii\base\Exception if the directory can't be created.
     */
    public function record(int $userId, string $wadKey, array $levels): int
    {
        $path = $this->getGameFile($userId, $wadKey);

        if ($path === null) {
            throw new RuntimeException("'$wadKey' is not a usable game key.");
        }

        $now = time();
        $data = $this->read($path);
        $applied = 0;

        foreach (array_slice($levels, 0, self::MAX_LINES) as $level) {
            if (!is_array($level) || !isset($level['line']) || !is_string($level['line'])) {
                continue;
            }

            // Absent, null and unrecognised all mean the same thing: an engine
            // that did not say. Never guessed at, because a wrong skill is worse
            // on this board than no skill.
            $skill = isset($level['skill']) && is_numeric($level['skill'])
                ? Skill::tryFrom((int)$level['skill'])
                : null;

            $result = $this->parseLine($level['line'], $skill);

            if ($result === null) {
                continue;
            }

            $key = $result->getKey();

            // A level that is already held keeps being updated; a new one is
            // refused once the file is full, rather than the file being trimmed
            // to fit it. Losing a record to make room for a record is worse
            // than not taking the new one.
            if (!isset($data[$key]) && count($data) >= self::MAX_LEVELS) {
                continue;
            }

            $data[$key] = $this->merge($data[$key] ?? null, $result, $now);
            $applied++;
        }

        if ($applied === 0) {
            return 0;
        }

        FileHelper::createDirectory(dirname($path));
        ksort($data);

        $json = json_encode($data, JSON_THROW_ON_ERROR);

        // LOCK_EX so a second tab cannot catch the file half written. It does
        // not make the read above part of the same lock: two tabs finishing a
        // level in the same second can still have one overwrite the other. For
        // a novelty leaderboard that is a fair trade against a lock file.
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException("Could not write $path.");
        }

        return $applied;
    }

    /**
     * One player's bests for one game, in level order.
     *
     * @return LevelBest[] Keyed by LevelBest::$key.
     */
    public function getBests(int $userId, string $wadKey): array
    {
        $path = $this->getGameFile($userId, $wadKey);

        if ($path === null) {
            return [];
        }

        $bests = [];

        foreach ($this->read($path) as $key => $row) {
            $best = $this->toBest((string)$key, $row);

            if ($best !== null) {
                $bests[$best->key] = $best;
            }
        }

        uasort($bests, fn(LevelBest $a, LevelBest $b) => $a->getOrder() <=> $b->getOrder());

        return $bests;
    }

    /**
     * The board for one game: every level anybody has finished, with who holds
     * each figure.
     *
     * Read across every player's file at the moment it is asked for, rather than
     * kept as a table of its own. There is no second copy to fall out of step,
     * and the cost is one small file per player who has played this game.
     *
     * @param int|null $viewerId Whose rows to mark as theirs.
     * @return array<int, array<string, mixed>> Rows in level order, one per
     * level and skill, each with a label, the skill, a play count, and a Leader
     * (or null) for each figure.
     */
    public function getLeaderboard(string $wadKey, ?int $viewerId = null): array
    {
        $root = $this->getStorageDir();

        if (!UserStorage::isSafeSegment($wadKey) || !is_dir($root)) {
            return [];
        }

        /** @var array<string, LevelBest[]> $byLevel Run key => user id => best. */
        $byLevel = [];
        $labels = [];
        $skills = [];
        $orders = [];

        foreach ((array)glob($root . '/*', GLOB_ONLYDIR) as $dir) {
            $userId = basename($dir);

            if (!ctype_digit($userId)) {
                continue;
            }

            foreach ($this->getBests((int)$userId, $wadKey) as $best) {
                $byLevel[$best->key][(int)$userId] = $best;
                $labels[$best->key] = $best->getLabel();
                $skills[$best->key] = $best->skill;
                $orders[$best->key] = $best->getOrder();
            }
        }

        if ($byLevel === []) {
            return [];
        }

        $names = $this->getNames($this->collectUserIds($byLevel));
        $rows = [];

        foreach ($byLevel as $key => $bests) {
            $rows[] = [
                'key' => $key,
                'label' => $labels[$key],
                'skill' => $skills[$key],
                'plays' => array_sum(array_map(fn(LevelBest $b) => $b->plays, $bests)),
                'players' => count($bests),
                // Lowest wins on time, highest on everything else. The date is
                // the tiebreak throughout: whoever got there first keeps it.
                'time' => $this->lead($bests, 'time', $names, $viewerId, true),
                'kills' => $this->lead($bests, 'kills', $names, $viewerId, false),
                'items' => $this->lead($bests, 'items', $names, $viewerId, false),
                'secrets' => $this->lead($bests, 'secrets', $names, $viewerId, false),
            ];
        }

        usort($rows, fn(array $a, array $b) => $orders[$a['key']] <=> $orders[$b['key']]);

        return $rows;
    }

    /**
     * A time as the engine's own stat file writes it: minutes, then seconds to a
     * hundredth. Kept public because it is the only place that knows tics are
     * what is stored.
     */
    public static function formatTime(int $tics): string
    {
        $seconds = ($tics % (60 * self::TICRATE)) / self::TICRATE;

        return sprintf('%d:%05.2f', intdiv($tics, 60 * self::TICRATE), $seconds);
    }

    /**
     * Every user id appearing anywhere in the board, once each.
     *
     * A loop rather than array_merge(...array_map('array_keys', $byLevel)):
     * $byLevel is keyed by run, those keys survive the map, and PHP takes
     * string keys in a spread as named arguments. "MAP01.3" is not a parameter
     * name, so that version threw the moment somebody had finished a level and
     * never before, which is the worst shape a bug can have.
     *
     * @param array<string, LevelBest[]> $byLevel
     * @return int[]
     */
    private function collectUserIds(array $byLevel): array
    {
        $userIds = [];

        foreach ($byLevel as $bests) {
            foreach (array_keys($bests) as $userId) {
                $userIds[] = (int)$userId;
            }
        }

        return array_values(array_unique($userIds));
    }

    /**
     * Works out who holds one figure on one level.
     *
     * @param LevelBest[] $bests Keyed by user id.
     * @param array<int, string> $names
     */
    private function lead(array $bests, string $metric, array $names, ?int $viewerId, bool $lowest): ?Leader
    {
        $at = $metric . 'At';
        $best = null;
        $bestId = null;
        $shared = 0;

        foreach ($bests as $userId => $candidate) {
            if ($candidate->plays === 0) {
                continue;
            }

            if ($best === null) {
                [$best, $bestId, $shared] = [$candidate, $userId, 0];

                continue;
            }

            $better = $lowest
                ? $candidate->$metric < $best->$metric
                : $candidate->$metric > $best->$metric;

            if ($better) {
                [$best, $bestId, $shared] = [$candidate, $userId, 0];

                continue;
            }

            if ($candidate->$metric !== $best->$metric) {
                continue;
            }

            $shared++;

            // Matched, so the earlier one keeps it. The other is still counted
            // as sharing it either way.
            if ($candidate->$at < $best->$at) {
                [$best, $bestId] = [$candidate, $userId];
            }
        }

        if ($best === null) {
            return null;
        }

        $of = $metric === 'time' ? 0 : $best->{$metric . 'Of'};

        return new Leader(
            $bestId,
            $names[$bestId] ?? '',
            $viewerId !== null && $bestId === $viewerId,
            $best->$metric,
            $of,
            $metric === 'time'
                ? self::formatTime($best->time)
                : $best->$metric . '/' . $of,
            $best->$at,
            $shared,
        );
    }

    /**
     * Display names for a set of user ids, in one query.
     *
     * Any status: a suspended or inactive user still set the time. One who has
     * really gone is left out, and the board says so rather than naming an id.
     *
     * @param int[] $userIds
     * @return array<int, string>
     */
    private function getNames(array $userIds): array
    {
        $userIds = array_values(array_unique($userIds));

        if ($userIds === []) {
            return [];
        }

        $names = [];

        foreach (User::find()->id($userIds)->status(null)->all() as $user) {
            $names[(int)$user->id] = $user->getName();
        }

        return $names;
    }

    /**
     * Folds one completed level into the row already held for it, keeping each
     * figure's own date with it.
     *
     * @param array<string, mixed>|null $row
     * @return array<string, int>
     */
    private function merge(?array $row, LevelResult $result, int $now): array
    {
        $held = $row !== null ? $this->toBest($result->getKey(), $row) : null;

        return [
            'time' => $held !== null ? min($held->time, $result->time) : $result->time,
            'timeAt' => $held !== null && $held->time <= $result->time ? $held->timeAt : $now,
            'kills' => $held !== null ? max($held->kills, $result->kills) : $result->kills,
            'killsOf' => $held !== null && $held->kills >= $result->kills ? $held->killsOf : $result->killsOf,
            'killsAt' => $held !== null && $held->kills >= $result->kills ? $held->killsAt : $now,
            'items' => $held !== null ? max($held->items, $result->items) : $result->items,
            'itemsOf' => $held !== null && $held->items >= $result->items ? $held->itemsOf : $result->itemsOf,
            'itemsAt' => $held !== null && $held->items >= $result->items ? $held->itemsAt : $now,
            'secrets' => $held !== null ? max($held->secrets, $result->secrets) : $result->secrets,
            'secretsOf' => $held !== null && $held->secrets >= $result->secrets ? $held->secretsOf : $result->secretsOf,
            'secretsAt' => $held !== null && $held->secrets >= $result->secrets ? $held->secretsAt : $now,
            'plays' => ($held !== null ? $held->plays : 0) + 1,
            'lastAt' => $now,
        ];
    }

    /**
     * One stored row back into a LevelBest, or null when the key or the row is
     * not one this service wrote. Files under @storage are not a trust boundary,
     * but a hand-edited one should be ignored rather than rendered.
     *
     * @param array<string, mixed> $row
     */
    private function toBest(string $key, mixed $row): ?LevelBest
    {
        $pattern = '/^(MAP\d{1,3}|E\d{1,2}M\d{1,2})(s?)\.(\d|' . Skill::UNKNOWN . ')$/';

        if (!is_array($row) || !preg_match($pattern, $key, $m)) {
            return null;
        }

        $int = static fn(string $name) => isset($row[$name]) && is_numeric($row[$name])
            ? max(0, (int)$row[$name])
            : 0;

        if ($int('plays') === 0) {
            return null;
        }

        return new LevelBest(
            $key,
            $m[1],
            $m[2] !== '',
            Skill::fromKey($m[3]),
            $int('time'),
            $int('timeAt'),
            $int('kills'),
            $int('killsOf'),
            $int('killsAt'),
            $int('items'),
            $int('itemsOf'),
            $int('itemsAt'),
            $int('secrets'),
            $int('secretsOf'),
            $int('secretsAt'),
            $int('plays'),
            $int('lastAt'),
        );
    }

    /**
     * The stored rows for one player and one game, or an empty array when there
     * is no usable file.
     *
     * @return array<string, mixed>
     */
    private function read(string $path): array
    {
        if (!is_file($path) || (int)@filesize($path) > self::MAX_FILE_BYTES) {
            return [];
        }

        $json = @file_get_contents($path);

        if (!is_string($json) || $json === '') {
            return [];
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    /**
     * Where one player's stats for one game live, or null when the game key is
     * not one that could have come from the WAD service.
     */
    private function getGameFile(int $userId, string $wadKey): ?string
    {
        if ($userId <= 0 || !UserStorage::isSafeSegment($wadKey)) {
            return null;
        }

        return $this->getStorageDir() . '/' . $userId . '/' . $wadKey . '.json';
    }
}
