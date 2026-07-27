<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Listeners;

use App\Modules\Counterparty\Application\RelationService;
use App\Modules\Counterparty\Domain\MovementKind;
use Illuminate\Support\Facades\Log;

/**
 * Moves the bilateral balance when a settlement completes
 * (docs/03-domain/10-counterparty.md §10.9).
 *
 * The event class belongs to Settlement, which this module may not depend on,
 * so it is subscribed to by string name and the payload is read defensively:
 * anything without the four fields we need is ignored rather than fataling a
 * queue worker. That is the price of the leaf position in the dependency graph,
 * and it is worth paying — Counterparty is read on every OTC quote, and a
 * cycle here would drag Settlement into the trading hot path.
 *
 * Only settlement drives the balance. Wiring TradeExecuted to the same service
 * would double-count: a trade and its settlement describe the same obligation
 * once when it is created and once when it is discharged, and the design doc's
 * handler applies the exchange exactly once, at settlement.
 */
final class ApplySettlementToRelation
{
    private const EVENT = 'App\Modules\Settlement\Events\SettlementCompleted';

    /** @var list<string> */
    private const REQUIRED = [
        'goldDelivererOrgId',
        'goldReceiverOrgId',
        'fineWeightMg',
        'cashAmountRial',
    ];

    public function __construct(private readonly RelationService $relations) {}

    public function handle(object $event): void
    {
        // Once Settlement ships, refuse anything that merely looks like its
        // event; until then, shape-checking is all we have.
        if (class_exists(self::EVENT) && ! is_a($event, self::EVENT)) {
            return;
        }

        foreach (self::REQUIRED as $property) {
            if (! property_exists($event, $property)) {
                Log::debug('Counterparty ignored a settlement event without the expected shape', [
                    'event' => $event::class,
                    'missing' => $property,
                ]);

                return;
            }
        }

        /** @var int $delivererOrgId */
        $delivererOrgId = $event->goldDelivererOrgId;
        /** @var int $receiverOrgId */
        $receiverOrgId = $event->goldReceiverOrgId;

        if ((int) $delivererOrgId === (int) $receiverOrgId) {
            return;
        }

        $reference = property_exists($event, 'settlementId')
            ? 'STL-'.$event->settlementId
            : null;

        // Signs are the design doc's: the side that hands over gold books the
        // gold out and the cash in. applyTrade mirrors it onto the other side.
        $this->relations->applyTrade(
            organizationId: (int) $delivererOrgId,
            counterpartyOrgId: (int) $receiverOrgId,
            goldDeltaMg: -(int) $event->fineWeightMg,
            rialDelta: (int) $event->cashAmountRial,
            reference: $reference,
            kind: MovementKind::SETTLEMENT,
            occurredAt: null,
            description: 'تسویه',
        );
    }
}
