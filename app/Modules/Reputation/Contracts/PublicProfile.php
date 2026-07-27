<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Contracts;

/**
 * The only reputation payload other members are ever shown
 * (docs/03-domain/14-reputation.md §14.9).
 *
 * The allow-list is the design: this object is constructed field by field from
 * the snapshot, so a column added to `reputation_stats` tomorrow cannot leak
 * into a public profile by accident. What §14.9 forbids — balances, named
 * counterparties, individual trade prices, AML flags, suspension reasons, KYC
 * data, the internal credit score — has no field here to land in.
 *
 * The member's display name is deliberately absent: it belongs to Identity, and
 * the presenter that already knows which organisation it is rendering joins it.
 *
 * Rates are basis points (9,980 = 99.8%).
 */
final readonly class PublicProfile
{
    public function __construct(
        public int $organizationId,
        public string $verificationTier,
        public string $tierLabel,
        public string $tierBadge,
        public int $totalTrades,
        public int $totalVolumeMg,
        public int $onTimeSettlementRateBps,
        public int $disputeRateBps,
        public int $avgSettlementMinutes,
        public int $distinctCounterparties,
        public int $rfqResponseRateBps,
        public int $rfqAvgResponseMinutes,
        public int $quoteFillRateBps,
        public string $memberSince,
        public ?string $lastActiveAt,
        public bool $isActive,
        public bool $isNewMember,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'verification_tier' => $this->verificationTier,
            'tier_label' => $this->tierLabel,
            'tier_badge' => $this->tierBadge,
            'total_trades' => $this->totalTrades,
            'total_volume_mg' => $this->totalVolumeMg,
            'on_time_settlement_rate_bps' => $this->onTimeSettlementRateBps,
            'dispute_rate_bps' => $this->disputeRateBps,
            'avg_settlement_minutes' => $this->avgSettlementMinutes,
            'distinct_counterparties' => $this->distinctCounterparties,
            'rfq_response_rate_bps' => $this->rfqResponseRateBps,
            'rfq_avg_response_minutes' => $this->rfqAvgResponseMinutes,
            'quote_fill_rate_bps' => $this->quoteFillRateBps,
            'member_since' => $this->memberSince,
            'last_active_at' => $this->lastActiveAt,
            'is_active' => $this->isActive,
            'is_new_member' => $this->isNewMember,
        ];
    }
}
