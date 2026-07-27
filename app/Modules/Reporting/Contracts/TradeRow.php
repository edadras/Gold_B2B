<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Contracts;

use JsonSerializable;

/**
 * One line of the trade list (§15.6 «فهرست معاملات»).
 *
 * Scalars only, and every quantity an integer in its smallest unit — a report
 * is the last place a float should be allowed to creep in, because the numbers
 * here end up in a member's own accounting software.
 */
final readonly class TradeRow implements JsonSerializable
{
    public function __construct(
        public int $tradeId,
        public string $executedOn,
        /** BUY or SELL, from this organisation's point of view. */
        public string $side,
        /** Where it happened: ORDER_BOOK, OTC, RFQ. */
        public string $venue,
        public int $fineMg,
        public int $purityX10k,
        public int $pricePerFineGram,
        public int $grossRial,
        public int $feeRial,
        public ?int $counterpartyOrgId = null,
        public ?string $counterpartyName = null,
    ) {}

    public function isBuy(): bool
    {
        return $this->side === 'BUY';
    }

    public function netRial(): int
    {
        return $this->isBuy()
            ? $this->grossRial + $this->feeRial
            : $this->grossRial - $this->feeRial;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'trade_id' => $this->tradeId,
            'executed_on' => $this->executedOn,
            'side' => $this->side,
            'venue' => $this->venue,
            'fine_mg' => $this->fineMg,
            'purity' => $this->purityX10k,
            'price_per_fine_gram' => $this->pricePerFineGram,
            'gross_rial' => $this->grossRial,
            'fee_rial' => $this->feeRial,
            'net_rial' => $this->netRial(),
            'counterparty' => $this->counterpartyName ?? $this->counterpartyOrgId,
        ];
    }
}
