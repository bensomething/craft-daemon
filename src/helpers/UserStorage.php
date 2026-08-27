<?php

namespace bensomething\daemon\helpers;

use craft\elements\User;
use craft\helpers\FileHelper;

/**
 * The rules shared by everything this plugin files under a user id.
 *
 * Savegames and level stats both live in @storage under <user id>/<wad key>,
 * which means both build paths out of strings and both leave a directory
 * behind when a user goes away. The rules are the same, so they are written
 * once.
 */
abstract class UserStorage
{
    /**
     * Whether a path segment is one this plugin will build a path out of.
     * Stricter than "contains no slashes": every segment the plugin generates
     * is a WAD key, an uppercase hex digest or an engine filename.
     */
    public static function isSafeSegment(string $segment): bool
    {
        // The leading underscore is allowed for one reason: it is the
        // placeholder used when the engine wrote a save with no directory of
        // its own.
        return (bool)preg_match('/^[A-Za-z0-9_][A-Za-z0-9._-]{0,63}$/', $segment)
            && !str_contains($segment, '..');
    }

    /**
     * Deletes the per-user directories under $root whose user no longer exists.
     *
     * Swept rather than hooked to a delete event: users are soft deleted first
     * and can be restored, so acting on the delete would take the files of
     * somebody who came back, and a sweep also clears orphans left by any
     * route.
     *
     * @return int How many users' directories were removed.
     * @throws \yii\base\ErrorException if a directory can't be deleted.
     */
    public static function sweepOrphans(string $root): int
    {
        if (!is_dir($root)) {
            return 0;
        }

        $removed = 0;

        foreach ((array)glob($root . '/*', GLOB_ONLYDIR) as $dir) {
            $userId = basename($dir);

            if (!ctype_digit($userId)) {
                continue;
            }

            // Any status, including suspended and inactive: only a user who is
            // really gone should lose their files. A soft deleted one is still
            // a row, so they keep theirs until Craft clears them out for good.
            if (User::find()->id((int)$userId)->status(null)->exists()) {
                continue;
            }

            FileHelper::removeDirectory($dir);
            $removed++;
        }

        return $removed;
    }
}
