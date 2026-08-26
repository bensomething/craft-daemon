<?php

namespace bensomething\daemon\models;

use Craft;

/**
 * One stored savegame.
 *
 * Everything except the timestamp is read out of the file or off its path, so
 * there is no sidecar metadata to keep in step. PrBoom writes the player's
 * description into the first 24 bytes, and the slot is the tail of the filename.
 */
class Save
{
    /**
     * @param string $id Identifies this save in a URL: its storage path relative to
     * the user's save directory, built from validated parts.
     * @param string $enginePath Where the engine expects this file, relative to its
     * own filesystem root. Restoring writes it back here.
     * @param string $description What the player called it. Empty when they saved
     * over a slot without typing anything.
     * @param int $slot The save slot, zero based, as the engine numbers them.
     * @param int $savedAt Unix timestamp taken when the save was uploaded.
     * @param int $size Bytes.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $enginePath,
        public readonly string $description,
        public readonly int $slot,
        public readonly int $savedAt,
        public readonly int $size,
    ) {
    }

    /** A label for the menu: the player's description, or the slot number. */
    public function getLabel(): string
    {
        return $this->description !== ''
            ? $this->description
            : Craft::t('daemon', 'Slot {number}', ['number' => $this->slot + 1]);
    }
}
