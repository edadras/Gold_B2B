<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Events;

/**
 * The payer asserted that they paid, off-platform (§5.3 pattern 2, step 3).
 *
 * No money has moved in the ledger at this point — this is an assertion, and
 * the guard clock of §5.3 ("reminder at 2h, operator at 4h, dispute at 24h")
 * starts here.
 */
final readonly class PaymentDeclared
{
    public function __construct(
        public int $settlementId,
        public int $paymentId,
        public int $payerOrganizationId,
        public int $payeeOrganizationId,
        public int $amountRial,
        public string $paymentMethod,
        public ?string $paymentReference,
        public bool $hasReceipt,
        public ?int $declaredByUserId,
        public string $occurredAt,
    ) {}
}
