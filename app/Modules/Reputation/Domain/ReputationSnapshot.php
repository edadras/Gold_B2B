<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Domain;

use App\Modules\Shared\Support\IntMath;
use Illuminate\Support\Carbon;

/**
 * The public metric set of docs/03-domain/14-reputation.md §14.3.
 *
 * Everything the tier rules and the public profile are allowed to look at, and
 * nothing else. There is deliberately no balance, no counterparty identity, no
 * AML flag and no internal credit score on this object: §14.2 makes leaking any
 * of them a compliance incident, and the cheapest way to guarantee that is for
 * the data never to reach the layer that renders a profile.
 *
 * The KYC booleans are the one exception to "performance data only" — they are
 * tier *inputs*, never rendered, and they carry no KYC content, only whether a
 * check passed.
 *
 * All derived figures are integer basis points. A percentage stored as a float
 * would drift between two renderings of the same profile.
 */
final readonly class ReputationSnapshot
{
    public function __construct(
        public int $organizationId,
        public int $totalTrades,
        public int $totalVolumeMg,
        public int $settlementsTotal,
        public int $settlementsOnTime,
        public int $settlementsLate,
        public int $settlementsDefaulted,
        public int $totalSettlementMinutes,
        public int $disputesInvolved,
        public int $disputesLost,
        public int $distinctCounterparties,
        public int $rfqReceived,
        public int $rfqResponded,
        public int $rfqTotalResponseMinutes,
        public int $quotesAccepted,
        public int $quotesFilled,
        public int $makerVolumeMg,
        public int $takerVolumeMg,
        public VerificationTier $tier,
        public string $memberSince,
        public ?string $lastActiveAt,
        public bool $kycBasicVerified = false,
        public bool $kycFullVerified = false,
        public bool $bankAccountVerified = false,
    ) {}

    /**
     * On-time settlement rate in basis points. A member with no settlements has
     * no rate — returning 100% would let a brand-new account clear the SILVER
     * bar on its first day, so an empty history scores zero and the trade-count
     * requirement is what actually gates the tier.
     */
    public function onTimeRateBps(): int
    {
        if ($this->settlementsTotal === 0) {
            return 0;
        }

        return IntMath::mulDivFloor($this->settlementsOnTime, 10_000, $this->settlementsTotal);
    }

    /** Lost disputes as a share of all trades (§14.3: "اختلاف بازنده / کل معامله"). */
    public function disputeRateBps(): int
    {
        if ($this->totalTrades === 0) {
            return 0;
        }

        return IntMath::mulDivFloor($this->disputesLost, 10_000, $this->totalTrades);
    }

    public function avgSettlementMinutes(): int
    {
        if ($this->settlementsTotal === 0) {
            return 0;
        }

        return intdiv($this->totalSettlementMinutes, $this->settlementsTotal);
    }

    public function rfqResponseRateBps(): int
    {
        if ($this->rfqReceived === 0) {
            return 0;
        }

        return IntMath::mulDivFloor($this->rfqResponded, 10_000, $this->rfqReceived);
    }

    public function rfqAvgResponseMinutes(): int
    {
        if ($this->rfqResponded === 0) {
            return 0;
        }

        return intdiv($this->rfqTotalResponseMinutes, $this->rfqResponded);
    }

    public function quoteFillRateBps(): int
    {
        if ($this->quotesAccepted === 0) {
            return 0;
        }

        return IntMath::mulDivFloor($this->quotesFilled, 10_000, $this->quotesAccepted);
    }

    /** Share of this member's volume where it was the maker (§14.4, PLATINUM). */
    public function makerShareBps(): int
    {
        $total = $this->makerVolumeMg + $this->takerVolumeMg;

        if ($total === 0) {
            return 0;
        }

        return IntMath::mulDivFloor($this->makerVolumeMg, 10_000, $total);
    }

    public function membershipDays(): int
    {
        return (int) Carbon::parse($this->memberSince)->startOfDay()->diffInDays(Carbon::now()->startOfDay());
    }

    /** §14.8 attack 4: a member that started over is always labelled as new. */
    public function isNewMember(): bool
    {
        return $this->membershipDays() < 90 || $this->totalTrades < 10;
    }
}
