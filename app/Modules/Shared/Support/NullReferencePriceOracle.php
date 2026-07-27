<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use App\Modules\Shared\Contracts\ReferencePriceOracle;

/** Default binding: no valuation available. Pricing replaces it when present. */
final class NullReferencePriceOracle implements ReferencePriceOracle
{
    public function pricePerFineGramRial(): ?int
    {
        return null;
    }
}
