<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application\Commands;

use App\Modules\Custody\Domain\Enums\LotShape;
use App\Modules\Shared\ValueObjects\Purity;
use App\Modules\Shared\ValueObjects\Weight;

/**
 * One bar coming out of the furnace.
 *
 * The purity here is the refiner's declaration, not a certificate: the output
 * is always DECLARED / UNDER_ASSAY until a laboratory says otherwise
 * (docs/03-domain/02-gold-lot-assay.md §2.6, hard rule).
 */
final readonly class MeltOutputSpec
{
    public function __construct(
        public Weight $gross,
        public Purity $declaredPurity,
        public LotShape $shape = LotShape::BAR,
        public ?string $serialNumber = null,
        public ?string $hallmarkCode = null,
    ) {}
}
