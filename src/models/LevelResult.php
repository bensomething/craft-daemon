<?php

namespace bensomething\daemon\models;

use bensomething\daemon\enums\Skill;

/**
 * One completed level, as the engine wrote it down.
 *
 * PrBoom+'s -levelstat option rewrites levelstat.txt every time a level is
 * exited, one line per level completed this session. This is one of those
 * lines, parsed. See Stats::parseLine().
 *
 * The skill does not come from that line. The engine writes no skill into its
 * stats, so the build prints it at every level exit and the host reads it off
 * stdout; null means an engine that was not printing it.
 */
class LevelResult
{
    /**
     * @param string $map The level, as the engine names it: MAP01 for Doom II
     * style WADs, E1M1 for episodic ones. Without the secret exit marker.
     * @param bool $secretExit Whether the level was left by its secret exit,
     * which is usually a shorter route and so a different run of the same map.
     * @param int $time How long the level took, in tics. The engine's own unit,
     * kept rather than converted so comparing two runs is exact.
     * @param int $kills Monsters killed.
     * @param int $killsOf Monsters on the level. Varies with skill.
     * @param int $items Items picked up.
     * @param int $itemsOf Items on the level.
     * @param int $secrets Secrets found.
     * @param int $secretsOf Secrets on the level. The same on every skill.
     * @param Skill|null $skill What it was played on, or null when the engine
     * did not say.
     */
    public function __construct(
        public readonly string $map,
        public readonly bool $secretExit,
        public readonly int $time,
        public readonly int $kills,
        public readonly int $killsOf,
        public readonly int $items,
        public readonly int $itemsOf,
        public readonly int $secrets,
        public readonly int $secretsOf,
        public readonly ?Skill $skill,
    ) {
    }

    /**
     * How this run is keyed in storage: the map, the engine's own secret exit
     * marker, and the skill. MAP15 and MAP15s stay two different runs because
     * the secret exit is usually the shorter road, and a skill of its own
     * because a time is only worth comparing against another at the same one.
     */
    public function getKey(): string
    {
        return $this->map
            . ($this->secretExit ? 's' : '')
            . '.' . ($this->skill?->key() ?? Skill::UNKNOWN);
    }
}
