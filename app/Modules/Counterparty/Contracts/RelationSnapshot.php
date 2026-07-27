<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Contracts;

/**
 * The public read model of one bilateral relation.
 *
 * Scalars only — other modules must never receive the Eloquent model, and the
 * `internal_note` field is deliberately absent because it is the owner's
 * private annotation about the counterparty.
 */
final readonly class RelationSnapshot
{
    public function __construct(
        public int $organizationId,
        public int $counterpartyOrgId,
        public int $goldBalanceMg,
        public int $rialBalance,
        public int $goldCreditLimitMg,
        public int $rialCreditLimit,
        public int $totalTradeCount,
        public int $totalVolumeMg,
        public int $overdueCount,
        public int $disputeCount,
        public bool $isTrusted,
        public bool $isBlocked,
        public bool $autoAcceptOtc,
        public ?string $firstTradeAt = null,
        public ?string $lastTradeAt = null,
    ) {}

    /** True when the counterparty owes us gold. */
    public function isGoldReceivable(): bool
    {
        return $this->goldBalanceMg > 0;
    }

    /** True when the counterparty owes us money. */
    public function isRialReceivable(): bool
    {
        return $this->rialBalance > 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'counterparty_org_id' => $this->counterpartyOrgId,
            'gold_balance_mg' => $this->goldBalanceMg,
            'rial_balance' => $this->rialBalance,
            'gold_credit_limit_mg' => $this->goldCreditLimitMg,
            'rial_credit_limit' => $this->rialCreditLimit,
            'total_trade_count' => $this->totalTradeCount,
            'total_volume_mg' => $this->totalVolumeMg,
            'overdue_count' => $this->overdueCount,
            'dispute_count' => $this->disputeCount,
            'is_trusted' => $this->isTrusted,
            'is_blocked' => $this->isBlocked,
            'auto_accept_otc' => $this->autoAcceptOtc,
            'first_trade_at' => $this->firstTradeAt,
            'last_trade_at' => $this->lastTradeAt,
        ];
    }
}
