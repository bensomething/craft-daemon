<?php

namespace bensomething\daemon\models;

/**
 * One WAD the plugin can load.
 *
 * Deliberately not a craft\base\Model: nothing here is posted, validated or
 * saved. It describes a file that already exists, so it is a value object.
 */
class Wad
{
    /**
     * @param string $key Identifies this WAD in a URL. Derived from the filename,
     * and only ever resolved by looking it up in the list the Wads service builds.
     * @param string $path Absolute path to the file.
     * @param string $name What the selector calls it: the admin's own name if they
     * set one, otherwise the derived name.
     * @param string $defaultName What the plugin would call it on its own. Shown as
     * the settings placeholder, so an empty field reads as "the usual name".
     * @param string $icon A Craft system icon name. The same for every WAD today,
     * but carried per WAD so making it vary stays a one-line change.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $path,
        public readonly string $name,
        public readonly string $defaultName,
        public readonly string $icon,
    ) {
    }
}
