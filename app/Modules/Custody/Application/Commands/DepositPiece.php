<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application\Commands;

use App\Modules\Custody\Domain\Enums\LotShape;
use App\Modules\Custody\Domain\Enums\PuritySource;
use App\Modules\Shared\ValueObjects\Purity;
use App\Modules\Shared\ValueObjects\Weight;

/**
 * One physical piece handed over at the counter, already re-weighed by the
 * vault officer (docs/03-domain/06-custody-vault.md §6.3 step 4).
 *
 * The weights recorded here are the measured ones, not the declared ones; the
 * caller is responsible for the ≥0.1% discrepancy stop-and-call-the-member
 * rule in step 5.
 */
final readonly class DepositPiece
{
    public function __construct(
        public Weight $gross,
        public Purity $purity,
        public PuritySource $puritySource,
        public LotShape $shape = LotShape::BAR,
        public ?string $serialNumber = null,
        public ?string $hallmarkCode = null,
        public ?int $refinerId = null,
        public ?string $refinedAt = null,
        public ?int $vaultBoxId = null,
    ) {}
}
