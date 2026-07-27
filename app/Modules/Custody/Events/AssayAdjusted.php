<?php

declare(strict_types=1);

namespace App\Modules\Custody\Events;

/**
 * A re-assay changed the fine weight of a lot that was already on the books.
 *
 * Custody does NOT write the ledger entry: it reports the signed delta and the
 * Ledger module posts ASSAY_ADJUSTMENT against the owner's GOLD/AVAILABLE
 * account. docs/03-domain/02-gold-lot-assay.md §2.4.
 */
final readonly class AssayAdjusted
{
    public function __construct(
        public int $lotId,
        public int $ownerOrganizationId,
        public int $previousAssayId,
        public int $newAssayId,
        public string $previousAssayCode,
        public string $newAssayCode,
        public int $previousPurityX10,
        public int $newPurityX10,
        public int $previousFineMg,
        public int $newFineMg,
        /** newFineMg - previousFineMg; negative means the lot lost fine weight. */
        public int $fineDeltaMg,
        public int $recordedByUserId,
        public string $occurredAt,
    ) {}
}
