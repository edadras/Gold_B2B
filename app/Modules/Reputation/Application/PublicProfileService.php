<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Application;

use App\Modules\Reputation\Contracts\PublicProfile;
use App\Modules\Reputation\Contracts\ReputationDirectory;
use App\Modules\Reputation\Domain\ReputationSnapshot;
use App\Modules\Reputation\Domain\VerificationTier;
use App\Modules\Reputation\Infrastructure\ReputationStat;
use Illuminate\Support\Carbon;

/**
 * Renders the member-facing profile of §14.3, filtered to what §14.9 permits.
 *
 * The filtering happens by construction: the service builds a `PublicProfile`
 * field by field and never hands out the statistics row, the snapshot or the
 * model. That is stronger than a blacklist, which only protects against the
 * fields somebody remembered to list.
 */
final class PublicProfileService implements ReputationDirectory
{
    public function __construct(private readonly StatsUpdater $stats) {}

    public function profile(int $organizationId): PublicProfile
    {
        $stat = ReputationStat::query()->find($organizationId);

        if ($stat === null) {
            return $this->emptyProfile($organizationId);
        }

        return $this->fromSnapshot($stat->toSnapshot());
    }

    /**
     * @param  list<int>  $organizationIds
     * @return array<int, PublicProfile>
     */
    public function profiles(array $organizationIds): array
    {
        $found = ReputationStat::query()
            ->whereIn('organization_id', $organizationIds)
            ->get()
            ->keyBy('organization_id');

        $profiles = [];

        foreach ($organizationIds as $organizationId) {
            $stat = $found->get($organizationId);

            $profiles[$organizationId] = $stat !== null
                ? $this->fromSnapshot($stat->toSnapshot())
                : $this->emptyProfile($organizationId);
        }

        return $profiles;
    }

    public function tier(int $organizationId): string
    {
        return $this->profile($organizationId)->verificationTier;
    }

    /**
     * The member's own detailed view (§14.9: "a member must see exactly its own
     * statistics" and be able to challenge them).
     *
     * Richer than the public profile — it exposes the numerators and
     * denominators behind each rate so a member can check the arithmetic — but
     * still only this member's own performance data.
     *
     * @return array<string, mixed>
     */
    public function ownStatistics(int $organizationId): array
    {
        $stat = ReputationStat::query()->find($organizationId);

        if ($stat === null) {
            return $this->emptyProfile($organizationId)->toArray();
        }

        $snapshot = $stat->toSnapshot();

        return array_merge($this->fromSnapshot($snapshot)->toArray(), [
            'settlements_total' => $snapshot->settlementsTotal,
            'settlements_on_time' => $snapshot->settlementsOnTime,
            'settlements_late' => $snapshot->settlementsLate,
            'settlements_defaulted' => $snapshot->settlementsDefaulted,
            'disputes_involved' => $snapshot->disputesInvolved,
            'disputes_lost' => $snapshot->disputesLost,
            'rfq_received' => $snapshot->rfqReceived,
            'rfq_responded' => $snapshot->rfqResponded,
            'quotes_accepted' => $snapshot->quotesAccepted,
            'quotes_filled' => $snapshot->quotesFilled,
            'maker_volume_mg' => $snapshot->makerVolumeMg,
            'taker_volume_mg' => $snapshot->takerVolumeMg,
            'maker_share_bps' => $snapshot->makerShareBps(),
            'min_countable_trade_mg' => $this->stats->minCountableTradeMg(),
        ]);
    }

    private function fromSnapshot(ReputationSnapshot $snapshot): PublicProfile
    {
        return new PublicProfile(
            organizationId: $snapshot->organizationId,
            verificationTier: $snapshot->tier->value,
            tierLabel: $snapshot->tier->label(),
            tierBadge: $snapshot->tier->badge(),
            totalTrades: $snapshot->totalTrades,
            totalVolumeMg: $snapshot->totalVolumeMg,
            onTimeSettlementRateBps: $snapshot->onTimeRateBps(),
            disputeRateBps: $snapshot->disputeRateBps(),
            avgSettlementMinutes: $snapshot->avgSettlementMinutes(),
            distinctCounterparties: $snapshot->distinctCounterparties,
            rfqResponseRateBps: $snapshot->rfqResponseRateBps(),
            rfqAvgResponseMinutes: $snapshot->rfqAvgResponseMinutes(),
            quoteFillRateBps: $snapshot->quoteFillRateBps(),
            // Month precision: the profile card shows "member since 1402/05",
            // and a to-the-second join date is more than anyone needs.
            memberSince: Carbon::parse($snapshot->memberSince)->format('Y-m'),
            lastActiveAt: $snapshot->lastActiveAt !== null
                ? Carbon::parse($snapshot->lastActiveAt)->toDateString()
                : null,
            isActive: $this->isActive($snapshot),
            isNewMember: $snapshot->isNewMember(),
        );
    }

    private function isActive(ReputationSnapshot $snapshot): bool
    {
        if ($snapshot->lastActiveAt === null) {
            return false;
        }

        $days = (int) config('goldb2b.reputation.active_within_days', 30);

        return Carbon::parse($snapshot->lastActiveAt)->greaterThanOrEqualTo(Carbon::now()->subDays($days));
    }

    private function emptyProfile(int $organizationId): PublicProfile
    {
        return new PublicProfile(
            organizationId: $organizationId,
            verificationTier: VerificationTier::BRONZE->value,
            tierLabel: VerificationTier::BRONZE->label(),
            tierBadge: VerificationTier::BRONZE->badge(),
            totalTrades: 0,
            totalVolumeMg: 0,
            onTimeSettlementRateBps: 0,
            disputeRateBps: 0,
            avgSettlementMinutes: 0,
            distinctCounterparties: 0,
            rfqResponseRateBps: 0,
            rfqAvgResponseMinutes: 0,
            quoteFillRateBps: 0,
            memberSince: Carbon::now()->format('Y-m'),
            lastActiveAt: null,
            isActive: false,
            isNewMember: true,
        );
    }
}
