<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Listeners;

use App\Modules\Reputation\Application\StatsUpdater;

/**
 * Records dispute outcomes (§14.3: the published rate counts disputes *lost*,
 * not disputes filed).
 *
 * Only a resolution moves the numbers. Counting an accusation would let a
 * member damage a competitor's public profile simply by filing claims, which is
 * the mirror image of the collusion attack in §14.8.
 */
final class UpdateStatsFromDispute
{
    private const RESOLVED = 'App\Modules\Dispute\Events\DisputeResolved';

    public function __construct(private readonly StatsUpdater $stats) {}

    public function handle(object $event): void
    {
        if (class_exists(self::RESOLVED) && ! is_a($event, self::RESOLVED)) {
            return;
        }

        if (! property_exists($event, 'initiatorOrgId') || ! property_exists($event, 'respondentOrgId')) {
            return;
        }

        $initiator = (int) $event->initiatorOrgId;
        $respondent = (int) $event->respondentOrgId;

        if ($initiator <= 0 || $respondent <= 0 || $initiator === $respondent) {
            return;
        }

        // `losingOrgId` is the module's own vocabulary; absent it, nobody loses.
        $loser = property_exists($event, 'losingOrgId') ? (int) $event->losingOrgId : 0;
        $frivolous = property_exists($event, 'wasFrivolous') && (bool) $event->wasFrivolous;

        $this->stats->recordDispute(
            $initiator,
            lost: $loser === $initiator,
            frivolous: $frivolous && $loser === $initiator,
        );

        $this->stats->recordDispute(
            $respondent,
            lost: $loser === $respondent,
        );
    }
}
