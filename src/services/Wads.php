<?php

namespace bensomething\doom\services;

use bensomething\doom\Plugin;
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
 * So there are two ways a WAD gets here, checked in this order:
 *
 *   1. An explicit path in the plugin settings.
 *   2. Anything in storage/doom/, which is where `doom/wad/fetch` puts things.
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
     * The four bytes every WAD opens with. Anything else is not a WAD, however
     * confidently it is named one.
     */
    private const MAGIC = ['IWAD', 'PWAD'];

    /**
     * Where fetched WADs are kept. Under @storage, so it is outside the web
     * root: WAD bytes reach the browser through a permission-gated controller
     * action, never as a static file.
     */
    public function getStorageDir(): string
    {
        return Craft::getAlias('@storage/doom');
    }

    /**
     * The WAD to load: the configured path if there is one, otherwise the
     * first valid WAD in storage. Null when there is nothing to play.
     */
    public function getWadPath(): ?string
    {
        $configured = Plugin::getInstance()->getSettings()->getWadPath();

        if ($configured !== null) {
            $path = Craft::getAlias($configured, false) ?: $configured;

            return $this->isValidWad($path) ? $path : null;
        }

        foreach ($this->getStoredWads() as $path) {
            return $path;
        }

        return null;
    }

    /**
     * Every valid WAD sitting in storage, sorted so the ordering is stable
     * across filesystems rather than whatever readdir() felt like.
     *
     * @return string[]
     */
    public function getStoredWads(): array
    {
        $dir = $this->getStorageDir();

        if (!is_dir($dir)) {
            return [];
        }

        $paths = array_filter(
            (array)glob($dir . '/*.[wW][aA][dD]'),
            fn(string $path) => $this->isValidWad($path),
        );

        sort($paths);

        return array_values($paths);
    }

    /**
     * Whether the file at $path is readable and opens with a WAD magic number.
     * Cheap enough to call on every request; it reads four bytes.
     */
    public function isValidWad(string $path): bool
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return false;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $magic = @fread($handle, 4);
        @fclose($handle);

        return is_string($magic) && in_array($magic, self::MAGIC, true);
    }

    /**
     * Downloads Freedoom, verifies it against the pinned checksum, and unpacks
     * freedoom1.wad and freedoom2.wad into storage.
     *
     * @return string[] Absolute paths of the WADs written.
     * @throws RuntimeException if the download, the checksum or the unpack fails.
     * @throws \yii\base\ErrorException if the temp directory can't be cleaned up.
     * @throws \yii\base\Exception if the storage directory can't be created.
     */
    public function fetchFreedoom(): array
    {
        $storageDir = $this->getStorageDir();
        FileHelper::createDirectory($storageDir);

        $tempDir = Craft::$app->getPath()->getTempPath() . '/doom-freedoom-' . bin2hex(random_bytes(6));
        FileHelper::createDirectory($tempDir);

        $zipPath = $tempDir . '/freedoom.zip';

        try {
            $this->download(self::FREEDOOM_URL, $zipPath);

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
    private function download(string $url, string $path): void
    {
        try {
            Craft::createGuzzleClient()->get($url, [
                'sink' => $path,
                'timeout' => 300,
                'connect_timeout' => 30,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException("Could not download {$url}: {$e->getMessage()}", 0, $e);
        }
    }
}
