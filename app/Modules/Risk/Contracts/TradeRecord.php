<?php

declare(strict_types=1);

namespace App\Modules\Risk\Contracts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * One executed trade as the AML rules need to see it. Scalars only — the rules
 * must never touch Trading's Eloquent models.
 */
final readonly class TradeRecord
{
    public function __construct(
        public int $id,
        public int $buyerOrganizationId,
        public int $sellerOrganizationId,
        public int $fineWeightMg,
        public int $pricePerGramRial,
        public CarbonImmutable $executedAt,
        public string $source = 'ORDER_BOOK',
    ) {}

    public static function make(
        int $id,
        int $buyerOrganizationId,
        int $sellerOrganizationId,
        int $fineWeightMg,
        int $pricePerGramRial,
        CarbonInterface $executedAt,
        string $source = 'ORDER_BOOK',
    ): self {
        return new self(
            $id,
            $buyerOrganizationId,
            $sellerOrganizationId,
            $fineWeightMg,
            $pricePerGramRial,
            CarbonImmutable::instance($executedAt),
            $source,
        );
    }

    public function involves(int $organizationId): bool
    {
        return $this->buyerOrganizationId === $organizationId
            || $this->sellerOrganizationId === $organizationId;
    }

    /** The other side of the trade, from $organizationId's point of view. */
    public function counterpartyOf(int $organizationId): ?int
    {
        return match ($organizationId) {
            $this->buyerOrganizationId => $this->sellerOrganizationId,
            $this->sellerOrganizationId => $this->buyerOrganizationId,
            default => null,
        };
    }
}
