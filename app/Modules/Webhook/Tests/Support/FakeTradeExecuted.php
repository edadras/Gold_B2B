<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Tests\Support;

/**
 * A stand-in for a producing module's domain event.
 *
 * The real `App\Modules\Trading\Events\TradeExecuted` is NOT used, and cannot
 * be: the architecture test forbids Webhook — tests included — from referencing
 * Trading at all. That constraint is the point of the design, not an obstacle to
 * it: the listener discovers everything by reflection and never names a type, so
 * a double with the same *shape* exercises exactly the production path. The test
 * registers this class in `goldb2b.webhook.event_map`, which is where the real
 * class names live too.
 *
 * The shape deliberately mirrors a real event: two counterparty organisations
 * with different property spellings (`…OrganizationId`), scalars, a null, and —
 * crucially — a non-scalar property that must NOT reach the payload.
 */
final class FakeTradeExecuted
{
    public function __construct(
        public int $tradeId,
        public string $tradeCode,
        public int $buyerOrganizationId,
        public int $sellerOrganizationId,
        public int $fineWeightMg,
        public int $pricePerGramRial,
        public ?string $makerSide,
        public string $executedAt,
        /** Must be skipped: an object in a webhook payload is a data leak. */
        public object $internalContext = new \stdClass,
    ) {}
}
