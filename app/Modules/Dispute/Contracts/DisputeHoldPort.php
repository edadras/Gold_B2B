<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Contracts;

/**
 * The ledger operations a dispute needs, expressed in plain integers.
 *
 * Why a port rather than calling Ledger\Contracts from the services: the
 * ledger's interfaces are typed in FineWeight and Rial and are implemented by a
 * module being built alongside this one. A port keeps the dispute services
 * testable with a recording double, and confines to a single class
 * (Infrastructure\Ledger\LedgerHoldAdapter) the knowledge of how a hold is
 * expressed in ledger terms — AVAILABLE → IN_DISPUTE with entry type
 * DISPUTE_HOLD, per §13.4.
 *
 * Every amount passed here has already been narrowed to the disputed quantity;
 * an implementation has no way to lock more than it is handed.
 */
interface DisputeHoldPort
{
    /**
     * Move the disputed fine weight from AVAILABLE to IN_DISPUTE.
     *
     * @return ?int the ledger entry landing in IN_DISPUTE, stored in
     *              `disputes.hold_gold_entry_id`; null when nothing was held
     */
    public function holdGold(int $organizationId, int $fineMg, int $disputeId): ?int;

    /** Move the disputed rial from AVAILABLE to IN_DISPUTE. */
    public function holdRial(int $organizationId, int $rial, int $disputeId): ?int;

    /**
     * Move the held fine weight back to AVAILABLE.
     *
     * Takes the amount as well as the entry id because the hold is a bucket
     * move, not a reservation: releasing it means moving the same quantity
     * back, and the entry id is carried for the audit trail.
     */
    public function releaseGoldHold(int $organizationId, int $fineMg, int $disputeId, ?int $entryId): void;

    /** Move the held rial back to AVAILABLE. */
    public function releaseRialHold(int $organizationId, int $rial, int $disputeId, ?int $entryId): void;

    /** Move fine gold between the two parties as an award. */
    public function transferGold(int $fromOrgId, int $toOrgId, int $fineMg, int $disputeId): void;

    /** Move rial between the two parties as an award. */
    public function transferRial(int $fromOrgId, int $toOrgId, int $rial, int $disputeId): void;

    /** Whether a real ledger is behind this port, for diagnostics and reports. */
    public function isOperational(): bool;
}
