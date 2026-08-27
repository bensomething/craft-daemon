<?php

namespace bensomething\daemon\models;

/**
 * Who holds one figure on one level, and what they managed.
 *
 * A leaderboard cell. The name is resolved when the board is built rather than
 * stored with the figure, so a renamed user is renamed on the board too, and
 * nothing on disk carries a copy of somebody's name.
 */
class Leader
{
    /**
     * @param int $userId Who holds it.
     * @param string $name What to call them. Empty when the user has gone and
     * garbage collection has not caught up.
     * @param bool $isCurrentUser Whether that is the person looking at the board.
     * @param int $value The figure itself: tics for a time, a count otherwise.
     * @param int $of What the count was out of, or zero for a time.
     * @param string $display The figure as it is written on screen. Formatted
     * where the metric is known rather than in the template, which would have to
     * be told which of these is a duration.
     * @param int $setAt When it was set.
     * @param int $sharedWith How many other players have matched it. Ties are
     * settled by who got there first, but a board that hides the tie is telling
     * only half the story.
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $name,
        public readonly bool $isCurrentUser,
        public readonly int $value,
        public readonly int $of,
        public readonly string $display,
        public readonly int $setAt,
        public readonly int $sharedWith,
    ) {
    }
}
