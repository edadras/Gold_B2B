<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Listeners;

use App\Modules\Broadcasting\Application\MemberBroadcaster;
use App\Modules\Broadcasting\Domain\EventShape;

/**
 * Ledger movements → `balance.updated` on `private-org.{orgId}`.
 *
 * NEVER THROTTLED (§3.5). BalanceUpdated does not implement ThrottledBroadcast,
 * so there is no path through BroadcastGateway that can drop one of these, and
 * BalanceIsNeverThrottledTest holds that line.
 *
 * PARTIAL BY DESIGN. A Ledger event reports the movement, not the resulting
 * four-bucket balance — BalanceReserved carries `availableAfter`,
 * ReservationReleased carries `stillReserved`, TransferCompleted carries
 * neither. Broadcasting may not query Ledger to fill in the rest, so the frame
 * carries only what the event knew and §3.5's "only the changed fields" makes
 * that a feature rather than a gap. The client treats the socket as an
 * invalidation signal and re-reads the authoritative balance over REST — which
 * is §3.4's «WebSocket برای به‌روزرسانی است، نه برای حقیقت» stated as code.
 *
 * TransferCompleted moves value between two organisations, so it broadcasts
 * twice — once to each side, each hearing only about itself.
 */
final class BroadcastBalance
{
    public function __construct(private readonly MemberBroadcaster $members) {}

    public function handle(object $event): void
    {
        $shape = EventShape::of($event);

        match ($this->basename($event)) {
            'BalanceReserved' => $this->reserved($shape),
            'ReservationReleased' => $this->released($shape),
            'TransferCompleted' => $this->transferred($shape),
            default => null,
        };
    }

    private function reserved(EventShape $shape): void
    {
        $organizationId = $shape->int('organizationId');
        $assetType = $shape->string('assetType');

        if ($organizationId === null || $assetType === null) {
            return;
        }

        $this->members->balanceUpdated(
            organizationId: $organizationId,
            assetType: $assetType,
            availableMg: $shape->int('availableAfter'),
            trigger: 'balance.reserved',
            reference: $this->reference($shape),
        );
    }

    private function released(EventShape $shape): void
    {
        $organizationId = $shape->int('organizationId');
        $assetType = $shape->string('assetType');

        if ($organizationId === null || $assetType === null) {
            return;
        }

        $this->members->balanceUpdated(
            organizationId: $organizationId,
            assetType: $assetType,
            reservedMg: $shape->int('stillReserved'),
            trigger: 'reservation.released',
            reference: $this->reference($shape),
        );
    }

    private function transferred(EventShape $shape): void
    {
        $assetType = $shape->string('assetType');
        $from = $shape->int('fromOrganizationId');
        $to = $shape->int('toOrganizationId');

        if ($assetType === null) {
            return;
        }

        $reference = $this->reference($shape);

        foreach ([$from, $to] as $organizationId) {
            if ($organizationId === null) {
                continue;
            }

            $this->members->balanceUpdated(
                organizationId: $organizationId,
                assetType: $assetType,
                trigger: 'transfer.completed',
                reference: $reference,
            );
        }
    }

    /**
     * "ORDER:44120" — what caused the move, in a form the client can link to.
     * Falls back to the ledger transaction group, which is always present and
     * is what an operator would search for anyway.
     */
    private function reference(EventShape $shape): ?string
    {
        $type = $shape->string('referenceType');
        $id = $shape->int('referenceId');

        if ($type !== null && $id !== null) {
            return $type.':'.$id;
        }

        return $shape->string('transactionGroup');
    }

    private function basename(object $event): string
    {
        $class = $event::class;
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
