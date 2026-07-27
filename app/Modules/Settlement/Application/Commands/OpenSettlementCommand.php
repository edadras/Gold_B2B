<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Application\Commands;

use App\Modules\Settlement\Domain\DeliveryMethod;
use App\Modules\Settlement\Domain\PaymentMethod;
use App\Modules\Settlement\Domain\SettlementType;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Everything needed to turn an executed trade into a settlement
 * (docs/03-domain/05-settlement.md §5.1).
 *
 * Built from a TradeExecuted event, from TradeReaderInterface, or directly by
 * a test. The four party ids are separate rather than derived from
 * buyer/seller because §5.1 keeps them separate: cross settlement (§5.7) and
 * on-account arrangements can route cash somewhere other than the gold
 * counterparty.
 */
final readonly class OpenSettlementCommand
{
    public function __construct(
        public int $tradeId,
        public int $goldDelivererOrgId,
        public int $goldReceiverOrgId,
        public int $cashPayerOrgId,
        public int $cashReceiverOrgId,
        public int $fineWeightMg,
        public int $cashAmountRial,
        public int $buyerFeeRial = 0,
        public int $sellerFeeRial = 0,
        public SettlementType $settlementType = SettlementType::T0,
        public DeliveryMethod $deliveryMethod = DeliveryMethod::CUSTODY_CHANGE,
        public PaymentMethod $paymentMethod = PaymentMethod::BANK_TRANSFER,
        public ?CarbonImmutable $deadlineAt = null,
        public ?int $parentSettlementId = null,
        public ?int $actorUserId = null,
    ) {
        if ($fineWeightMg <= 0) {
            throw new InvalidArgumentException('A settlement must move a positive fine weight');
        }

        if ($cashAmountRial < 0 || $buyerFeeRial < 0 || $sellerFeeRial < 0) {
            throw new InvalidArgumentException('Settlement amounts cannot be negative');
        }

        if ($goldDelivererOrgId === $goldReceiverOrgId) {
            throw new InvalidArgumentException('Gold deliverer and receiver must differ');
        }

        if ($cashPayerOrgId === $cashReceiverOrgId) {
            throw new InvalidArgumentException('Cash payer and receiver must differ');
        }
    }

    /** Convenience for the ordinary case: buyer pays, seller delivers. */
    public static function forTrade(
        int $tradeId,
        int $buyerOrganizationId,
        int $sellerOrganizationId,
        int $fineWeightMg,
        int $cashAmountRial,
        int $buyerFeeRial = 0,
        int $sellerFeeRial = 0,
        SettlementType $settlementType = SettlementType::T0,
        ?CarbonImmutable $deadlineAt = null,
    ): self {
        return new self(
            tradeId: $tradeId,
            goldDelivererOrgId: $sellerOrganizationId,
            goldReceiverOrgId: $buyerOrganizationId,
            cashPayerOrgId: $buyerOrganizationId,
            cashReceiverOrgId: $sellerOrganizationId,
            fineWeightMg: $fineWeightMg,
            cashAmountRial: $cashAmountRial,
            buyerFeeRial: $buyerFeeRial,
            sellerFeeRial: $sellerFeeRial,
            settlementType: $settlementType,
            deadlineAt: $deadlineAt,
        );
    }

    /** F9 — what the payer owes, and therefore what gets locked. */
    public function totalCashDueRial(): int
    {
        return $this->cashAmountRial + $this->buyerFeeRial;
    }
}
