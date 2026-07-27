<?php

declare(strict_types=1);

namespace App\Modules\Custody\Contracts;

use App\Modules\Custody\Contracts\DTO\AllocationPlan;
use App\Modules\Custody\Domain\Enums\CustodianType;
use App\Modules\Custody\Domain\Exceptions\InsufficientGoldLotsException;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Purity;

/**
 * Picks the lots that will deliver a requested fine weight.
 *
 * Signature follows docs/03-domain/02-gold-lot-assay.md §2.10.
 */
interface LotAllocatorInterface
{
    /**
     * Strategy: exact match first, then FIFO by acquisition date, minimising
     * the number of splits (at most one). Rows are locked FOR UPDATE in
     * ascending id order, so the caller must already be inside a transaction.
     *
     * @throws InsufficientGoldLotsException when the owner cannot cover the requirement
     */
    public function allocate(
        int $ownerOrganizationId,
        FineWeight $required,
        ?Purity $minPurity = null,
        ?CustodianType $custodianType = null,
    ): AllocationPlan;

    /** Fine weight the owner could allocate right now, without locking. */
    public function availableFineWeight(
        int $ownerOrganizationId,
        ?Purity $minPurity = null,
        ?CustodianType $custodianType = null,
    ): FineWeight;
}
