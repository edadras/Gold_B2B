<?php

declare(strict_types=1);

/*
 * Reputation module defaults, merged into `goldb2b.reputation` by the module
 * provider. Kept inside the module so its knobs travel with its code.
 */
return [
    /*
     * Anti-gaming, §14.8 attack 1: a trade below this fine weight does not count
     * toward the public statistics at all. Ten grams is small enough that no
     * legitimate wholesale trade is excluded and large enough that inflating a
     * trade count becomes expensive rather than free.
     *
     * The trade still happens, still settles and still moves the ledger — only
     * the reputation figures ignore it.
     */
    'min_countable_trade_mg' => 10_000,

    /*
     * §14.4: after a demotion, promotion is barred for this long. The point is
     * that a member cannot trade its way back overnight and pretend the
     * incident never happened.
     */
    'promotion_lock_days' => 90,

    // Minimum length of a demotion reason. A reason of "x" is not a reason.
    'min_demotion_reason_length' => 10,

    // A member with no activity for this long is shown as inactive (§14.3).
    'active_within_days' => 30,
];
