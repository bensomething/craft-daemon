<?php

namespace bensomething\daemon\services;

use bensomething\daemon\models\Save;
use Craft;
use craft\helpers\FileHelper;
use RuntimeException;
use yii\base\Component;

/**
 * Keeping savegames.
 *
 * Dwasm mounts IDBFS and syncs after every save, so they survive a reload. What
 * they do not survive is the browser: clear site data or switch machines and
 * they are gone. This is a second home for them, in @storage, per user. A copy,
 * not a replacement.
 *
 * Nothing about a save is stored alongside it. The player's description is the
 * first 24 bytes of the file, the slot is the tail of the engine's filename,
 * and the time is the name of the file we write, so metadata cannot drift.
 */
class Saves extends Component
{
    /**
     * How many versions of each slot to keep. Enough to undo a bad decision, few
     * enough that a novelty doesn't grow a gigabyte of Doom in storage.
     */
    public const MAX_VERSIONS = 10;

    /**
     * The largest save accepted. PrBoom savegames run to a few hundred KB, so this
     * is loose enough never to be hit and tight enough not to be an upload endpoint.
     */
    public const MAX_BYTES = 8388608;

    /**
     * How many bytes of description PrBoom writes at the head of a savegame.
     * SAVESTRINGSIZE in g_game.c.
     */
    private const DESCRIPTION_BYTES = 24;

    /**
     * The extension the engine writes and the only one accepted.
     */
    private const EXTENSION = '.dsg';

    /** Where saves are kept. Under @storage, like the WADs, and for the same reason. */
    public function getStorageDir(): string
    {
        return Craft::getAlias('@storage/daemon/saves');
    }

    /**
     * Everything stored for one user and one game, newest first.
     *
     * @return Save[] Keyed by Save::$id.
     */
    public function getSaves(int $userId, string $wadKey): array
    {
        $dir = $this->getGameDir($userId, $wadKey);

        if ($dir === null || !is_dir($dir)) {
            return [];
        }

        $saves = [];

        // <engine directory>/<engine filename>/<timestamp>.dsg
        foreach ((array)glob($dir . '/*/*/*' . self::EXTENSION) as $path) {
            $save = $this->readSave($dir, $path);

            if ($save !== null) {
                $saves[$save->id] = $save;
            }
        }

        uasort($saves, fn(Save $a, Save $b) => $b->savedAt <=> $a->savedAt);

        return $saves;
    }

    /**
     * The newest version of each slot, which is what a fresh page needs to put the
     * player back where they were.
     *
     * @return Save[] Keyed by Save::$id.
     */
    public function getLatestSaves(int $userId, string $wadKey): array
    {
        $latest = [];
        $seen = [];

        // getSaves() is newest first, so the first of each path wins.
        foreach ($this->getSaves($userId, $wadKey) as $save) {
            if (isset($seen[$save->enginePath])) {
                continue;
            }

            $seen[$save->enginePath] = true;
            $latest[$save->id] = $save;
        }

        return $latest;
    }

    /**
     * Stores one savegame and prunes older versions of the same slot.
     *
     * @param string $enginePath Where the file sits in the engine's own filesystem,
     * relative to its save root.
     * @param string $contents The raw .dsg bytes.
     * @throws RuntimeException if the path or the contents are unusable.
     * @throws \yii\base\Exception if the directory can't be created.
     * @throws \yii\base\ErrorException if pruning can't delete an old version.
     */
    public function store(int $userId, string $wadKey, string $enginePath, string $contents): Save
    {
        if ($contents === '') {
            throw new RuntimeException('Refusing to store an empty savegame.');
        }

        if (strlen($contents) > self::MAX_BYTES) {
            throw new RuntimeException('Savegame is larger than ' . self::MAX_BYTES . ' bytes.');
        }

        $parts = $this->parseEnginePath($enginePath);

        if ($parts === null) {
            throw new RuntimeException("Refusing to store a savegame at '$enginePath'.");
        }

        $gameDir = $this->getGameDir($userId, $wadKey);

        if ($gameDir === null) {
            throw new RuntimeException("'$wadKey' is not a usable game key.");
        }

        [$engineDir, $engineFile] = $parts;
        $slotDir = $gameDir . '/' . $engineDir . '/' . $engineFile;
        FileHelper::createDirectory($slotDir);

        // Milliseconds, not seconds. The filename is the version's identity,
        // so two writes landing in the same tick would silently replace each
        // other rather than becoming two versions. Still an integer, so the
        // filenames still sort as dates.
        $path = $slotDir . '/' . (int)round(microtime(true) * 1000) . self::EXTENSION;

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Could not write $path.");
        }

        $this->prune($slotDir);

        $save = $this->readSave($gameDir, $path);

        if ($save === null) {
            throw new RuntimeException("Wrote $path but could not read it back.");
        }

        return $save;
    }

    /**
     * The bytes of one stored save, or null if the id matches nothing. The id is
     * resolved by rebuilding the path from validated parts, so a request can name a
     * file but never describe one.
     */
    public function read(int $userId, string $wadKey, string $id): ?string
    {
        $path = $this->resolve($userId, $wadKey, $id);

        if ($path === null || !is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return is_string($contents) ? $contents : null;
    }

    /**
     * Turns a save id back into an absolute path, or null when the id is not one
     * this service could have issued.
     */
    private function resolve(int $userId, string $wadKey, string $id): ?string
    {
        $gameDir = $this->getGameDir($userId, $wadKey);

        if ($gameDir === null) {
            return null;
        }

        $parts = explode('/', $id);

        if (count($parts) !== 3) {
            return null;
        }

        [$engineDir, $engineFile, $timestamp] = $parts;

        if (
            !$this->isSafeSegment($engineDir) ||
            !$this->isSafeSegment($engineFile) ||
            !ctype_digit($timestamp)
        ) {
            return null;
        }

        return $gameDir . '/' . $engineDir . '/' . $engineFile . '/' . $timestamp . self::EXTENSION;
    }

    /**
     * The directory holding one user's saves for one game, or null when the game key
     * isn't one that could have come from the WAD service.
     */
    private function getGameDir(int $userId, string $wadKey): ?string
    {
        if ($userId <= 0 || !$this->isSafeSegment($wadKey)) {
            return null;
        }

        return $this->getStorageDir() . '/' . $userId . '/' . $wadKey;
    }

    /**
     * Whether a path segment is one this service will build a path out of. Stricter
     * than "contains no slashes": every segment the plugin generates is a WAD key,
     * an uppercase hex digest or an engine filename.
     */
    private function isSafeSegment(string $segment): bool
    {
        // The leading underscore is allowed for one reason: it is the
        // placeholder this service uses when the engine wrote a save with no
        // directory of its own.
        return (bool)preg_match('/^[A-Za-z0-9_][A-Za-z0-9._-]{0,63}$/', $segment)
            && !str_contains($segment, '..');
    }

    /**
     * Splits an engine path into its directory and its filename without the
     * extension, or null if it is not a shape the engine produces.
     *
     * PrBoom writes saves into a directory named after an MD5 of the loaded WADs,
     * but that can be turned off in its own menu, leaving no directory at all. The
     * placeholder keeps the layout on disk the same either way.
     *
     * @return string[]|null [directory, filename without extension]
     */
    private function parseEnginePath(string $enginePath): ?array
    {
        if (!str_ends_with(strtolower($enginePath), self::EXTENSION)) {
            return null;
        }

        // Absolute paths are refused rather than trimmed into relative ones.
        // The engine never sends one, and quietly rewriting a path into
        // something acceptable is how a check stops being a check.
        if (str_starts_with($enginePath, '/')) {
            return null;
        }

        $enginePath = substr($enginePath, 0, -strlen(self::EXTENSION));
        $segments = explode('/', $enginePath);

        if (count($segments) === 1) {
            array_unshift($segments, '_');
        }

        if (count($segments) !== 2) {
            return null;
        }

        foreach ($segments as $segment) {
            if (!$this->isSafeSegment($segment)) {
                return null;
            }
        }

        return $segments;
    }

    /**
     * Reads one stored file into a Save, or null when it isn't one.
     */
    private function readSave(string $gameDir, string $path): ?Save
    {
        $id = substr($path, strlen($gameDir) + 1, -strlen(self::EXTENSION));
        $parts = explode('/', $id);

        if (count($parts) !== 3 || !ctype_digit($parts[2])) {
            return null;
        }

        [$engineDir, $engineFile, $timestamp] = $parts;
        $size = @filesize($path);

        if ($size === false) {
            return null;
        }

        // The placeholder means the engine had no directory of its own, so the
        // file goes back at the root of its save location.
        $enginePath = ($engineDir === '_' ? '' : $engineDir . '/') . $engineFile . self::EXTENSION;

        return new Save(
            $id,
            $enginePath,
            $this->readDescription($path),
            $this->readSlot($engineFile),
            intdiv((int)$timestamp, 1000),
            $size,
        );
    }

    /**
     * The player's own description, out of the head of the file. Whatever is there
     * was typed into a Doom menu, so anything outside printable ASCII is dropped.
     */
    private function readDescription(string $path): string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        $head = @fread($handle, self::DESCRIPTION_BYTES);
        @fclose($handle);

        if (!is_string($head)) {
            return '';
        }

        $head = explode("\0", $head)[0];

        return trim((string)preg_replace('/[^\x20-\x7E]/', '', $head));
    }

    /**
     * The slot number off the tail of the engine's filename, which runs
     * `<savegamename><slot>.dsg`. Zero when there is no number, which is also the
     * first slot.
     */
    private function readSlot(string $engineFile): int
    {
        return preg_match('/(\d+)$/', $engineFile, $m) ? (int)$m[1] : 0;
    }

    /**
     * Drops all but the newest MAX_VERSIONS files in one slot directory.
     *
     * @throws \yii\base\ErrorException if a file can't be deleted.
     */
    private function prune(string $slotDir): void
    {
        $paths = (array)glob($slotDir . '/*' . self::EXTENSION);

        if (count($paths) <= self::MAX_VERSIONS) {
            return;
        }

        // Filenames are unix timestamps in milliseconds, all the same length,
        // so a string sort is a date sort right up until the year 2286.
        sort($paths);

        foreach (array_slice($paths, 0, count($paths) - self::MAX_VERSIONS) as $path) {
            FileHelper::unlink($path);
        }
    }
}
