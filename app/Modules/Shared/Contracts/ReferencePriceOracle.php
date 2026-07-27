<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

/**
 * The current reference price of one fine gram, in rial.
 *
 * `GET /balances` quotes `market_value_rial`, but the balances endpoint belongs
 * to Ledger and Ledger may not depend on Pricing (see the module graph in
 * tests/Architecture/ArchitectureTest.php). Shared declares the port, Pricing
 * binds the adapter, and a slice without Pricing simply omits the field.
 *
 * Returns null rather than 0 when no price is available: a valuation of zero
 * and "we cannot value this right now" must not look the same to a client.
 */
interface ReferencePriceOracle
{
    public function pricePerFineGramRial(): ?int;
}
