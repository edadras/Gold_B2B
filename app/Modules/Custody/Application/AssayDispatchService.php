<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application;

use App\Modules\Custody\Domain\Enums\CustodianType;
use App\Modules\Custody\Domain\Enums\CustodyOperationStatus;
use App\Modules\Custody\Domain\Enums\CustodyOperationType;
use App\Modules\Custody\Domain\Exceptions\CustodyEntityNotFoundException;
use App\Modules\Custody\Domain\Exceptions\LotNotOwnedException;
use App\Modules\Custody\Infrastructure\Models\CustodyOperationModel;
use App\Modules\Custody\Infrastructure\Models\GoldLotModel;
use Illuminate\Support\Facades\DB;

/**
 * `POST /lots/{id}/send-to-assay` — asking for a piece to be re-assayed.
 *
 * ADDED FOR THE HTTP LAYER. AssayService records a certificate that already
 * exists; nothing owned the step before it, where the member says "take this
 * bar to a laboratory". The docs list the endpoint (§2.10) but the module had
 * no method for it, and the two halves of the operation must not be left to a
 * controller to sequence:
 *
 *   1. the lot is put ON_HOLD (VaultService::hold), so it cannot be sold,
 *      allocated or withdrawn while it is out of the owner's hands. Skipping
 *      this is the failure mode that matters: metal in a courier's van that is
 *      still AVAILABLE on the order book is metal that can be sold twice.
 *   2. a SEND_TO_ASSAY row is written to custody_operations, REQUESTED rather
 *      than COMPLETED — the lot has not physically moved yet; a vault officer
 *      hands it over and AssayService closes the loop when the certificate
 *      comes back.
 *
 * The operation is lossless on paper (input fine == output fine): the lot is
 * unchanged by being carried somewhere. Any difference the laboratory measures
 * is an assay adjustment, which is AssayService's business, not this one's.
 */
final class AssayDispatchService
{
    public function __construct(
        private readonly VaultService $vault,
        private readonly CustodyOperationRecorder $recorder,
    ) {}

    /**
     * @param  int  $ownerOrganizationId  the caller's organisation; re-checked here
     *                                    so the rule survives a non-HTTP caller
     *
     * @throws CustodyEntityNotFoundException|LotNotOwnedException
     */
    public function send(
        int $lotId,
        int $ownerOrganizationId,
        int $requestedByUserId,
        ?int $laboratoryId = null,
        ?string $reason = null,
    ): CustodyOperationModel {
        $lot = GoldLotModel::query()->find($lotId);

        if (! $lot instanceof GoldLotModel) {
            throw new CustodyEntityNotFoundException('GoldLot', $lotId);
        }

        // Ownership is asserted in the service as well as at the HTTP boundary:
        // the boundary answers 404 to hide the id, this one refuses the act.
        if ((int) $lot->owner_organization_id !== $ownerOrganizationId) {
            throw new LotNotOwnedException($lotId, $ownerOrganizationId);
        }

        $reason ??= 'ارسال برای ری‌گیری';

        // hold() owns its own transaction, validates the status transition and
        // writes the lot_status_events row. It throws if the lot is already
        // encumbered, which is exactly the answer the member should get.
        $held = $this->vault->hold(
            lotId: $lotId,
            reason: $reason,
            byUserId: $requestedByUserId,
            referenceType: 'assay_dispatch',
            referenceId: $laboratoryId,
        );

        return DB::transaction(fn (): CustodyOperationModel => $this->recorder->record(
            type: CustodyOperationType::SEND_TO_ASSAY,
            inputLotIds: [$lotId],
            outputLotIds: [$lotId],
            inputFineMg: (int) $held->fine_weight_mg,
            outputFineMg: (int) $held->fine_weight_mg,
            lossFineMg: 0,
            requestedByUserId: $requestedByUserId,
            status: CustodyOperationStatus::REQUESTED,
            vaultId: $held->custodian_type === CustodianType::VAULT ? (int) $held->custodian_id : null,
            organizationId: $ownerOrganizationId,
            fromLocation: $held->physical_location,
            reason: $reason,
            referenceType: $laboratoryId === null ? null : 'laboratory',
            referenceId: $laboratoryId,
        ));
    }
}
