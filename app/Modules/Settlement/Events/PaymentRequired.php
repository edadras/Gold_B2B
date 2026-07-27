<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Events;

/**
 * A settlement is now waiting for one member's money.
 *
 * Distinct from SettlementOpened, which says the obligation exists and the
 * assets are locked. A settlement can be opened and then routed to the netting
 * queue instead, in which case nobody is asked to pay anything — so "opened"
 * and "pay now" are two different facts and only the second one should reach a
 * member's phone.
 *
 * It names `cashPayerOrgId` because that is the only party being asked for
 * anything. The receiving side finds out when the payment is declared.
 *
 * Fired after the transaction commits, so a listener that reads the settlement
 * back sees PAYMENT_PENDING.
 */
final readonly class PaymentRequired
{
    public function __construct(
        public int $settlementId,
        public string $settlementCode,
        public int $cashPayerOrgId,
        public int $cashReceiverOrgId,
        /** What the payer owes, in rial — F9's buyer net. */
        public int $amountRial,
        public string $deadlineAt,
        public string $occurredAt,
    ) {}
}
