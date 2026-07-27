<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Events;

/**
 * The payee confirmed receipt, and this is the moment rial actually moves
 * (§5.2 transition table: PAYMENT_DECLARED → PAYMENT_CONFIRMED, "انتقال ریال").
 *
 * transactionGroup is carried so Accounting can tie its voucher to the exact
 * ledger group without querying back.
 */
final readonly class PaymentConfirmed
{
    public function __construct(
        public int $settlementId,
        public int $paymentId,
        public int $payerOrganizationId,
        public int $payeeOrganizationId,
        public int $amountRial,
        public int $buyerFeeRial,
        public int $sellerFeeRial,
        public ?int $confirmedByUserId,
        public ?string $transactionGroup,
        public string $occurredAt,
    ) {}
}
