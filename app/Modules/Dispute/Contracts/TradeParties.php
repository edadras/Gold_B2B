<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Contracts;

/**
 * Who was on each side of a trade, and the facts a claim is computed from.
 *
 * Scalars only: this crosses a module boundary and Trading's own types are off
 * limits to this module.
 */
final readonly class TradeParties
{
    public function __construct(
        public int $tradeId,
        public int $buyerOrgId,
        public int $sellerOrgId,
        /** Fine weight the trade was booked at, in milligrams. */
        public int $fineMg = 0,
        /** Purity the trade declared, ×10,000. */
        public int $purityX10k = 0,
        /** Rial per fine gram at execution. */
        public int $pricePerFineGram = 0,
        public int $grossRial = 0,
        public ?int $settlementId = null,
        public ?int $goldLotId = null,
    ) {}

    public function includes(int $organizationId): bool
    {
        return $organizationId === $this->buyerOrgId || $organizationId === $this->sellerOrgId;
    }

    /** The other side, given one side. */
    public function counterpartyOf(int $organizationId): ?int
    {
        return match ($organizationId) {
            $this->buyerOrgId => $this->sellerOrgId,
            $this->sellerOrgId => $this->buyerOrgId,
            default => null,
        };
    }
}
