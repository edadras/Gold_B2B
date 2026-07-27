<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application;

use App\Modules\Custody\Domain\Enums\LotStatus;
use App\Modules\Custody\Domain\Exceptions\CustodyEntityNotFoundException;
use App\Modules\Custody\Domain\Exceptions\LotNotAvailableException;
use App\Modules\Custody\Domain\Exceptions\LotNotOwnedException;
use App\Modules\Custody\Domain\TransitionContext;
use App\Modules\Custody\Events\OwnershipTransferred;
use App\Modules\Custody\Infrastructure\Models\GoldLotModel;
use Illuminate\Support\Facades\DB;

/**
 * Changes the legal owner of a lot without moving the metal —
 * docs/03-domain/06-custody-vault.md §6.2 and §2.3 hard rule
 * ("ownership only changes through Settlement or a dual-approved correction").
 *
 * Settlement calls this at the end of a completed settlement; the physical
 * location and custodian are untouched.
 */
final readonly class LotOwnershipService
{
    public function __construct(private LotStateMachine $stateMachine) {}

    public function transfer(
        int $lotId,
        int $fromOrganizationId,
        int $toOrganizationId,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $actorUserId = null,
    ): GoldLotModel {
        if ($fromOrganizationId === $toOrganizationId) {
            throw new LotNotOwnedException($lotId, $toOrganizationId);
        }

        $lot = DB::transaction(function () use ($lotId, $fromOrganizationId, $toOrganizationId, $referenceType, $referenceId, $actorUserId): GoldLotModel {
            $lot = GoldLotModel::query()->whereKey($lotId)->lockForUpdate()->first();

            if (! $lot instanceof GoldLotModel) {
                throw new CustodyEntityNotFoundException('GoldLot', $lotId);
            }

            if ((int) $lot->owner_organization_id !== $fromOrganizationId) {
                throw new LotNotOwnedException($lotId, $fromOrganizationId);
            }

            if (! $lot->status->isLive()) {
                throw new LotNotAvailableException($lotId, $lot->status, 'TRANSFER_OWNERSHIP');
            }

            $lot->owner_organization_id = $toOrganizationId;
            $lot->version = (int) $lot->version + 1;
            $lot->save();

            // A settled lot comes back to AVAILABLE under its new owner.
            if ($lot->status === LotStatus::IN_SETTLEMENT) {
                $this->stateMachine->transition(
                    $lot,
                    LotStatus::AVAILABLE,
                    new TransitionContext(
                        actorUserId: $actorUserId,
                        reason: 'Ownership transferred to organization '.$toOrganizationId,
                        referenceType: $referenceType,
                        referenceId: $referenceId,
                    ),
                );
            }

            return $lot;
        }, attempts: 3);

        event(new OwnershipTransferred(
            lotId: (int) $lot->id,
            lotCode: (string) $lot->lot_code,
            fromOrganizationId: $fromOrganizationId,
            toOrganizationId: $toOrganizationId,
            fineWeightMg: (int) $lot->fine_weight_mg,
            referenceType: $referenceType,
            referenceId: $referenceId,
            actorUserId: $actorUserId,
            occurredAt: now()->toIso8601String(),
        ));

        return $lot;
    }
}
