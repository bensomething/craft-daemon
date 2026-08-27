<?php

namespace bensomething\daemon\enums;

use Craft;

/**
 * The five skill levels, as the engine numbers them.
 *
 * skill_t in doomdef.h, zero based, which is worth saying out loud: the menu
 * presents them one based, so "Skill 3" in the engine's own log is the fourth
 * item on the screen.
 *
 * The engine does not write the skill into its level stats, so it is printed at
 * every level exit by a patch this plugin applies to the build. See
 * bin/build-engine.sh.
 */
enum Skill: int
{
    case Baby = 0;
    case Easy = 1;
    case Medium = 2;
    case Hard = 3;
    case Nightmare = 4;

    /**
     * How a skill is keyed in storage. 'u' for a level completed by an engine
     * that was not printing the skill, which is any build older than the patch.
     */
    public const UNKNOWN = 'u';

    /**
     * The case for a stored key, or null for the unknown marker and for anything
     * that is not one of the five.
     */
    public static function fromKey(string $key): ?self
    {
        return ctype_digit($key) ? self::tryFrom((int)$key) : null;
    }

    /** How this skill is keyed in storage. */
    public function key(): string
    {
        return (string)$this->value;
    }

    /**
     * The short form, for the column. Long enough to be recognised by anyone who
     * has played, short enough not to own the table.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::Baby => Craft::t('daemon', 'ITYTD'),
            self::Easy => Craft::t('daemon', 'HNTR'),
            self::Medium => Craft::t('daemon', 'HMP'),
            self::Hard => Craft::t('daemon', 'UV'),
            self::Nightmare => Craft::t('daemon', 'NM'),
        };
    }

    /**
     * The name off the menu, for the tooltip. Doom's own wording: a WAD can
     * replace the graphics, but the slots mean the same thing in all of them.
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::Baby => Craft::t('daemon', "I'm Too Young To Die"),
            self::Easy => Craft::t('daemon', 'Hey, Not Too Rough'),
            self::Medium => Craft::t('daemon', 'Hurt Me Plenty'),
            self::Hard => Craft::t('daemon', 'Ultra-Violence'),
            self::Nightmare => Craft::t('daemon', 'Nightmare!'),
        };
    }
}
