<?php

namespace bensomething\daemon\services;

use bensomething\daemon\models\Wad;
use bensomething\daemon\Plugin;
use Craft;
use craft\helpers\FileHelper;
use RuntimeException;
use Throwable;
use yii\base\Component;
use ZipArchive;

/**
 * Locating, validating and acquiring WAD files.
 *
 * No WAD ships in this package. id's shareware WAD can't be redistributed
 * inside a Composer package on terms anyone would want to defend, and Freedoom
 * (which could be, it's BSD) is 28.8MB per IWAD landing in every vendor/ on
 * every deploy, which is a lot to inflict on a novelty.
 *
 * So WADs are found rather than shipped, in two directories, searched in this
 * order:
 *
 *   1. A directory named in the plugin settings, if there is one.
 *   2. storage/daemon/, which is where `daemon/wad/fetch` puts things.
 */
class Wads extends Component
{
    public const FREEDOOM_VERSION = '0.13.0';

    public const FREEDOOM_URL = 'https://github.com/freedoom/freedoom/releases/download/v0.13.0/freedoom-0.13.0.zip';

    /**
     * Pinned rather than read from the release's CHECKSUM file: a checksum
     * fetched from the same host as the artefact only proves the two agree.
     * Taken from the PGP-signed freedoom-0.13.0-CHECKSUM.
     */
    public const FREEDOOM_SHA256 = '3f9b264f3e3ce503b4fb7f6bdcb1f419d93c7b546f4df3e874dd878db9688f59';

    /**
     * Where the Doom shareware IWAD is fetched from.
     *
     * Doomworld rather than id: id's own distribution is a DOS self-extracting
     * installer (DEICE.EXE plus split archives) that nothing here can unpack.
     * The mirror is not trusted, which is the point of the hash below.
     */
    public const SHAREWARE_URL = 'https://www.doomworld.com/3ddownloads/ports/shareware_doom_iwad.zip';

    /**
     * SHA-256 of the shareware DOOM1.WAD inside that archive.
     *
     * Derived from a download whose MD5 matched the value Debian's
     * game-data-packager publishes for shareware v1.9
     * (f0cefca49926d00903cf57551d901abe), which is maintained independently of
     * whoever is serving the bytes. Verifying the WAD rather than the archive
     * checks the thing that matters: repackage the zip however you like, the
     * game data still has to be the game data.
     */
    public const SHAREWARE_SHA256 = '1d7d43be501e67d927e415e0b8f3e29c3bf33075e859721816f652a526cac771';

    /**
     * What the shareware WAD is called once installed. The archive's own name
     * is uppercase, and PrBoom reads the filename when it works out which game
     * it is looking at, so it is normalised on the way in.
     */
    public const SHAREWARE_FILENAME = 'doom1.wad';

    /**
     * The four bytes an IWAD opens with. An IWAD is a complete game: it holds
     * the maps, the textures, the sounds, everything.
     */
    public const MAGIC_IWAD = 'IWAD';

    /**
     * The four bytes a PWAD opens with. A PWAD is a patch, loaded on top of an
     * IWAD, and it is not a game on its own. Passing one to -iwad gets you a
     * warning followed by a crash the moment the engine looks for a texture
     * that isn't there.
     */
    public const MAGIC_PWAD = 'PWAD';

    /**
     * The four bytes every WAD opens with. Anything else is not a WAD, however
     * confidently it is named one.
     */
    private const MAGIC = [self::MAGIC_IWAD, self::MAGIC_PWAD];

    /**
     * The IWADs this engine plays, by filename.
     *
     * Anything not listed here still loads. It just gets its filename in the
     * menu, because guessing a title from a filename is how you end up calling
     * someone's WAD "Doom2 Final REAL v3".
     */
    private const KNOWN_WADS = [
        'doom.wad' => 'Doom',
        'doom1.wad' => 'Doom (shareware)',
        'doomu.wad' => 'The Ultimate Doom',
        'doom2.wad' => 'Doom II',
        'doom2f.wad' => 'Doom II (French)',
        'tnt.wad' => 'Final Doom: TNT Evilution',
        'plutonia.wad' => 'Final Doom: The Plutonia Experiment',
        'freedoom1.wad' => 'Freedoom: Phase 1',
        'freedoom2.wad' => 'Freedoom: Phase 2',
        'freedm.wad' => 'FreeDM',
        'chex.wad' => 'Chex Quest',
    ];

    /**
     * The Craft system icon shown beside every game in the menu.
     *
     * One icon for all of them: the menu distinguishes games by name, and an
     * icon that varies without carrying information is just noise with a
     * gradient on it.
     */
    public const ICON = 'floppy-disk';

    /**
     * Where fetched WADs are kept. Under @storage, so it is outside the web
     * root: WAD bytes reach the browser through a permission-gated controller
     * action, never as a static file.
     */
    public function getStorageDir(): string
    {
        return Craft::getAlias('@storage/daemon');
    }

    /**
     * Every directory searched for WADs, in the order they are searched: the
     * configured one first, so an admin who points the plugin at their own
     * collection gets their own default, then storage.
     *
     * @return string[]
     */
    public function getSearchDirs(): array
    {
        $dirs = [];
        $configured = Plugin::getInstance()->getSettings()->getWadDir();

        if ($configured !== null) {
            $dirs[] = $configured;
        }

        $dirs[] = $this->getStorageDir();

        return $dirs;
    }

    /**
     * Every game that can be played, in the order the selector lists them.
     *
     * IWADs only. A PWAD is a patch loaded on top of a game, not a game, and
     * offering one as a choice would offer a crash.
     *
     * A search directory that doesn't exist contributes nothing and is not an
     * error: the setting can hold a $VAR that only some environments define.
     *
     * @return Wad[] Keyed by Wad::$key.
     */
    public function getAvailableWads(): array
    {
        $wads = [];
        $seen = [];
        $settings = Plugin::getInstance()->getSettings();
        $names = $settings->wadNames;

        foreach ($this->getSearchDirs() as $dir) {
            foreach ($this->findWads($dir, self::MAGIC_IWAD) as $path) {
                // The configured directory can be the storage directory, or a
                // symlink to it, and the same WAD twice would be two menu
                // items that do the same thing.
                $real = realpath($path) ?: $path;

                if (isset($seen[$real])) {
                    continue;
                }

                $seen[$real] = true;

                $key = $this->uniqueKey($path, $wads);
                $defaultName = self::nameFor($path);

                $wads[$key] = new Wad(
                    $key,
                    $path,
                    $names[$key] ?? $defaultName,
                    $defaultName,
                    self::ICON,
                );
            }
        }

        // The chosen default is moved to the front rather than flagged in
        // place, so "the default" stays "the first one" everywhere that reads
        // this list: the Game menu, the console, getDefaultWad(). A key naming
        // nothing here is ignored, because the same setting is deployed to
        // environments holding a different set of WADs.
        //
        // Union rather than a sort: keys from the left operand win, so the
        // duplicate further down is dropped and the rest keep their order.
        if ($settings->defaultWad !== null && isset($wads[$settings->defaultWad])) {
            $wads = [$settings->defaultWad => $wads[$settings->defaultWad]] + $wads;
        }

        return $wads;
    }

    /**
     * The WAD a request asked for, falling back to the default when the key is
     * missing or matches nothing.
     *
     * Unknown keys fall back rather than 404: a bookmark that outlived the WAD
     * it named should still give you a game.
     */
    public function getRequestedWad(?string $key): ?Wad
    {
        $wads = $this->getAvailableWads();

        if ($key !== null && isset($wads[$key])) {
            return $wads[$key];
        }

        return $wads === [] ? null : reset($wads);
    }

    /**
     * The WAD loaded when nothing has been asked for: whichever the settings
     * screen marked as the default, or the first one found.
     */
    public function getDefaultWad(): ?Wad
    {
        return $this->getRequestedWad(null);
    }

    /**
     * The path of the default WAD. Null when there is nothing to play.
     */
    public function getWadPath(): ?string
    {
        return $this->getDefaultWad()?->path;
    }

    /**
     * What the plugin calls the WAD at $path on its own. Known IWADs get their
     * real title; anything else gets its filename, which is the most honest
     * thing left to say about it.
     *
     * This is the fallback, not the last word: an admin's own name from the
     * settings screen wins over it.
     */
    public static function nameFor(string $path): string
    {
        $filename = strtolower(basename($path));

        return self::KNOWN_WADS[$filename] ?? pathinfo($path, PATHINFO_FILENAME);
    }

    /**
     * The URL key for the WAD at $path: its filename, reduced to characters
     * that survive a query string without escaping.
     */
    public static function keyFor(string $path): string
    {
        $key = strtolower(pathinfo($path, PATHINFO_FILENAME));
        $key = trim((string)preg_replace('/[^a-z0-9]+/', '-', $key), '-');

        return $key !== '' ? $key : 'wad';
    }

    /**
     * Every PWAD found. These are not playable and never appear in the Game
     * menu, but they are worth reporting: a PWAD sitting in a search directory
     * is somebody expecting it to show up.
     *
     * @return string[]
     */
    public function getPatchWads(): array
    {
        $paths = [];

        foreach ($this->getSearchDirs() as $dir) {
            foreach ($this->findWads($dir, self::MAGIC_PWAD) as $path) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * Every valid WAD sitting in storage. The settings screen asks for this
     * specifically, because that is the directory `daemon/wad/fetch` writes to
     * and the one the Download button fills.
     *
     * @return string[]
     */
    public function getStoredWads(): array
    {
        return $this->findWads($this->getStorageDir());
    }

    /**
     * Whether the file at $path is readable and opens with a WAD magic number.
     * Cheap enough to call on every request; it reads four bytes.
     */
    public function isValidWad(string $path): bool
    {
        return in_array($this->readMagic($path), self::MAGIC, true);
    }

    /**
     * Whether the file at $path is a complete game rather than a patch. Only
     * IWADs can be played on their own, so only IWADs are offered as games.
     */
    public function isIwad(string $path): bool
    {
        return $this->readMagic($path) === self::MAGIC_IWAD;
    }

    /**
     * The file's first four bytes, or null if they can't be read. Cheap enough
     * to call on every request; it reads four bytes.
     */
    private function readMagic(string $path): ?string
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $magic = @fread($handle, 4);
        @fclose($handle);

        return is_string($magic) ? $magic : null;
    }

    /**
     * Downloads Freedoom, verifies it against the pinned checksum, and unpacks
     * freedoom1.wad and freedoom2.wad into storage.
     *
     * @param callable|null $onProgress Called as ($downloadedBytes, $totalBytes)
     * while the archive downloads. $totalBytes is 0 until the response headers
     * land, and the callback fires very frequently: throttle in the caller.
     * @return string[] Absolute paths of the WADs written.
     * @throws RuntimeException if the download, the checksum or the unpack fails.
     * @throws \yii\base\ErrorException if the temp directory can't be cleaned up.
     * @throws \yii\base\Exception if the storage directory can't be created.
     */
    public function fetchFreedoom(?callable $onProgress = null): array
    {
        $storageDir = $this->getStorageDir();
        FileHelper::createDirectory($storageDir);

        $tempDir = Craft::$app->getPath()->getTempPath() . '/daemon-freedoom-' . bin2hex(random_bytes(6));
        FileHelper::createDirectory($tempDir);

        $zipPath = $tempDir . '/freedoom.zip';

        try {
            $this->download(self::FREEDOOM_URL, $zipPath, $onProgress);

            $actual = hash_file('sha256', $zipPath);

            if (!hash_equals(self::FREEDOOM_SHA256, (string)$actual)) {
                throw new RuntimeException(sprintf(
                    'Checksum mismatch: expected %s, got %s. Refusing to install.',
                    self::FREEDOOM_SHA256,
                    $actual,
                ));
            }

            $extractDir = $tempDir . '/extracted';
            FileHelper::createDirectory($extractDir);

            $this->unzip($zipPath, $extractDir);

            // The archive nests everything under freedoom-<version>/, but
            // don't rely on that: match at the root too.
            $found = (array)glob($extractDir . '/{,*/}freedoom?.wad', GLOB_BRACE);

            if ($found === []) {
                throw new RuntimeException('No WADs found inside the Freedoom archive.');
            }

            $written = [];

            foreach ($found as $source) {
                $target = $storageDir . '/' . basename($source);
                copy($source, $target);
                $written[] = $target;
            }

            sort($written);

            return $written;
        } finally {
            try {
                FileHelper::removeDirectory($tempDir);
            } catch (Throwable $e) {
                Craft::warning("Could not clean up {$tempDir}: {$e->getMessage()}", __METHOD__);
            }
        }
    }

    /**
     * Every valid WAD in $dir, sorted so the ordering is stable across
     * filesystems rather than whatever readdir() felt like.
     *
     * @param string|null $magic Restrict to one kind, MAGIC_IWAD or
     * MAGIC_PWAD. Null accepts either.
     * @return string[]
     */
    private function findWads(string $dir, ?string $magic = null): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $paths = array_filter(
            (array)glob($dir . '/*.[wW][aA][dD]'),
            fn(string $path) => $magic !== null
                ? $this->readMagic($path) === $magic
                : $this->isValidWad($path),
        );

        sort($paths);

        return array_values($paths);
    }

    /**
     * keyFor(), made unique against the keys already in $taken.
     *
     * Filenames are unique within a directory but the configured path can come
     * from anywhere, so two WADs really can arrive with the same basename.
     *
     * @param Wad[] $taken
     */
    private function uniqueKey(string $path, array $taken): string
    {
        $base = self::keyFor($path);
        $key = $base;
        $suffix = 2;

        while (isset($taken[$key])) {
            $key = $base . '-' . $suffix++;
        }

        return $key;
    }

    /**
     * Downloads the Doom shareware IWAD and installs it into storage.
     *
     * The shareware episode is not free software. id's licence allows the
     * shareware distribution to be passed around, so this fetches it at an
     * admin's explicit instruction, onto their own machine. It is not bundled,
     * because a Composer package carrying id's game data is a package that is
     * no longer wholly MIT and GPL, and every install would be redistributing
     * it without being asked.
     *
     * @param callable|null $onProgress Called as ($downloadedBytes, $totalBytes)
     * while the archive downloads. $totalBytes is 0 until the response headers
     * land, and the callback fires very frequently: throttle in the caller.
     * @return string The absolute path of the WAD written.
     * @throws RuntimeException if the download, the checksum or the unpack fails.
     * @throws \yii\base\ErrorException if the temp directory can't be cleaned up.
     * @throws \yii\base\Exception if the storage directory can't be created.
     */
    public function fetchShareware(?callable $onProgress = null): string
    {
        $storageDir = $this->getStorageDir();
        FileHelper::createDirectory($storageDir);

        $tempDir = Craft::$app->getPath()->getTempPath() . '/daemon-shareware-' . bin2hex(random_bytes(6));
        FileHelper::createDirectory($tempDir);

        $zipPath = $tempDir . '/shareware.zip';

        try {
            $this->download(self::SHAREWARE_URL, $zipPath, $onProgress);

            $extractDir = $tempDir . '/extracted';
            FileHelper::createDirectory($extractDir);

            $this->unzip($zipPath, $extractDir);

            $found = (array)glob($extractDir . '/{,*/}*.[wW][aA][dD]', GLOB_BRACE);

            if ($found === []) {
                throw new RuntimeException('No WAD found inside the shareware archive.');
            }

            $source = reset($found);
            $actual = hash_file('sha256', $source);

            if (!hash_equals(self::SHAREWARE_SHA256, (string)$actual)) {
                throw new RuntimeException(sprintf(
                    'Checksum mismatch: expected %s, got %s. Refusing to install.',
                    self::SHAREWARE_SHA256,
                    $actual,
                ));
            }

            $target = $storageDir . '/' . self::SHAREWARE_FILENAME;
            copy($source, $target);

            return $target;
        } finally {
            try {
                FileHelper::removeDirectory($tempDir);
            } catch (Throwable $e) {
                Craft::warning("Could not clean up {$tempDir}: {$e->getMessage()}", __METHOD__);
            }
        }
    }

    /**
     * Unpacks a zip archive.
     *
     * Craft 5 has no unzip helper (the Craft 3 Zip helper is long gone), so
     * this is ZipArchive directly, which is why composer.json requires ext-zip.
     *
     * @throws RuntimeException if the archive can't be opened or extracted.
     */
    private function unzip(string $zipPath, string $extractDir): void
    {
        $zip = new ZipArchive();
        $opened = $zip->open($zipPath);

        if ($opened !== true) {
            throw new RuntimeException("Could not open the Freedoom archive (ZipArchive error {$opened}).");
        }

        try {
            if (!$zip->extractTo($extractDir)) {
                throw new RuntimeException('Could not extract the Freedoom archive.');
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Streams a URL to disk. Streamed rather than buffered because Freedoom is
     * ~20MB and there is no reason to hold that in PHP's memory.
     *
     * @throws RuntimeException if the request fails.
     */
    private function download(string $url, string $path, ?callable $onProgress = null): void
    {
        $options = [
            'sink' => $path,
            'timeout' => 300,
            'connect_timeout' => 30,
        ];

        if ($onProgress !== null) {
            // Guzzle's signature is (downloadTotal, downloadedBytes, uploadTotal,
            // uploadedBytes). Only the download half is meaningful here, and the
            // totals arrive as 0 until the response headers do.
            $options['progress'] = static function($downloadTotal, $downloadedBytes) use ($onProgress) {
                $onProgress((int)$downloadedBytes, (int)$downloadTotal);
            };
        }

        try {
            Craft::createGuzzleClient()->get($url, $options);
        } catch (Throwable $e) {
            throw new RuntimeException("Could not download {$url}: {$e->getMessage()}", 0, $e);
        }
    }
}
