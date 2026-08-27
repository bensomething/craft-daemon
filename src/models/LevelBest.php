<?php

namespace bensomething\daemon\models;

use bensomething\daemon\enums\Skill;
use Craft;

/**
 * One player's best on one level.
 *
 * Not a run: each figure is the best that player has managed, and they need not
 * have come from the same visit. Fastest time and most secrets are separate
 * achievements, so they carry separate dates.
 */
class LevelBest
{
    /**
     * @param string $key The run as it is keyed and sorted, secret exit marker
     * and skill included: MAP01.3, MAP15s.4, E1M1.u.
     * @param string $map The level without the marker.
     * @param bool $secretExit Whether this is the secret exit route.
     * @param Skill|null $skill What it was played on, or null when the engine
     * that recorded it was not printing the skill.
     * @param int $time Fastest completion, in tics.
     * @param int $timeAt When that was set.
     * @param int $kills Most monsters killed, and $killsOf how many there were
     * on the run that managed it.
     * @param int $items Most items picked up, of $itemsOf.
     * @param int $secrets Most secrets found, of $secretsOf.
     * @param int $plays How many times this level has been completed.
     * @param int $lastAt The most recent completion.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $map,
        public readonly bool $secretExit,
        public readonly ?Skill $skill,
        public readonly int $time,
        public readonly int $timeAt,
        public readonly int $kills,
        public readonly int $killsOf,
        public readonly int $killsAt,
        public readonly int $items,
        public readonly int $itemsOf,
        public readonly int $itemsAt,
        public readonly int $secrets,
        public readonly int $secretsOf,
        public readonly int $secretsAt,
        public readonly int $plays,
        public readonly int $lastAt,
    ) {
    }

    /**
     * The level as it is written on screen. The engine's marker is a bare 's',
     * which means nothing to anyone who has not read the source.
     */
    public function getLabel(): string
    {
        return $this->secretExit
            ? Craft::t('daemon', '{map} (secret exit)', ['map' => $this->map])
            : $this->map;
    }

    /**
     * Where this run sorts: episode, then map, then the normal exit before the
     * secret one, then hardest skill first. E1M1 has an episode, MAP01 does not
     * and takes zero, so the two naming schemes never interleave within one WAD.
     * An unknown skill sorts last, below Nightmare, because it is the least
     * useful row on the board rather than the most impressive.
     *
     * @return int[]
     */
    public function getOrder(): array
    {
        $skill = -($this->skill?->value ?? -1);

        if (preg_match('/^E(\d+)M(\d+)$/', $this->map, $m)) {
            return [(int)$m[1], (int)$m[2], $this->secretExit ? 1 : 0, $skill];
        }

        return [0, (int)substr($this->map, 3), $this->secretExit ? 1 : 0, $skill];
    }
}
