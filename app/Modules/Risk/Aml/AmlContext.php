<?php

declare(strict_types=1);

namespace App\Modules\Risk\Aml;

use App\Modules\Risk\Contracts\TradeIntent;
use App\Modules\Risk\Contracts\TradeSide;
use Carbon\CarbonImmutable;

/**
 * Everything a rule may look at, in scalars. Rules reach further back in time
 * through TradeHistoryReaderInterface; nothing else.
 */
final readonly class AmlContext
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public AmlEventType $eventType,
        public int $organizationId,
        public ?int $userId = null,
        public ?int $counterpartyOrgId = null,
        public ?int $buyerOrganizationId = null,
        public ?int $sellerOrganizationId = null,
        public int $fineWeightMg = 0,
        public int $pricePerGramRial = 0,
        public int $amountRial = 0,
        public ?CarbonImmutable $occurredAt = null,
        public ?string $subjectType = null,
        public ?int $subjectId = null,
        public array $metadata = [],
    ) {}

    /** Build the pre-trade context straight from a TradeIntent. */
    public static function fromIntent(TradeIntent $intent): self
    {
        $isBuy = $intent->side === TradeSide::BUY;

        return new self(
            eventType: AmlEventType::TRADE_INTENT,
            organizationId: $intent->organizationId,
            userId: $intent->userId,
            counterpartyOrgId: $intent->counterpartyOrgId,
            buyerOrganizationId: $isBuy ? $intent->organizationId : $intent->counterpartyOrgId,
            sellerOrganizationId: $isBuy ? $intent->counterpartyOrgId : $intent->organizationId,
            fineWeightMg: $intent->fineWeightMg,
            pricePerGramRial: $intent->priceRial,
            amountRial: $intent->grossValue()->amount,
            occurredAt: $intent->at(),
        );
    }

    public function at(): CarbonImmutable
    {
        return $this->occurredAt ?? CarbonImmutable::now();
    }

    public function metadata(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /** The organization on the other side, whichever seat this member is in. */
    public function otherSide(): ?int
    {
        if ($this->counterpartyOrgId !== null) {
            return $this->counterpartyOrgId;
        }

        return match ($this->organizationId) {
            $this->buyerOrganizationId => $this->sellerOrganizationId,
            $this->sellerOrganizationId => $this->buyerOrganizationId,
            default => null,
        };
    }

    public function isSelfTrade(): bool
    {
        if ($this->buyerOrganizationId !== null && $this->sellerOrganizationId !== null) {
            return $this->buyerOrganizationId === $this->sellerOrganizationId;
        }

        return $this->counterpartyOrgId !== null
            && $this->counterpartyOrgId === $this->organizationId;
    }
}
