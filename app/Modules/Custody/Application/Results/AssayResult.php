<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application\Results;

/**
 * Outcome of recording a certificate.
 *
 * $fineDeltaMg is signed: negative means the lot turned out to hold less pure
 * gold than the books said. The Ledger posts ASSAY_ADJUSTMENT from it; Custody
 * does not touch the ledger (docs/03-domain/02-gold-lot-assay.md §2.4).
 */
final readonly class AssayResult
{
    public function __construct(
        public int $assayId,
        public string $assayCode,
        public int $goldLotId,
        public int $ownerOrganizationId,
        public ?int $previousAssayId,
        public ?string $previousAssayCode,
        public int $previousPurityX10,
        public int $newPurityX10,
        public int $previousFineMg,
        public int $newFineMg,
        public int $fineDeltaMg,
        public string $puritySource,
        public string $lotStatus,
    ) {}

    public function isReAssay(): bool
    {
        return $this->previousAssayId !== null;
    }

    public function changedBalance(): bool
    {
        return $this->fineDeltaMg !== 0;
    }
}
