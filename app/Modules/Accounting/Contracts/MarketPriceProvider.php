<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Contracts;

/**
 * Read model for the current market price, used only by the informational
 * unrealised P&L figure (F17).
 *
 * Accounting is not allowed to depend on Pricing (see the module dependency
 * graph), and it should not: a valuation number that never touches the journal
 * has no business creating a hard coupling. The default binding returns null,
 * which makes UnrealizedPnlService report "no market price available" rather
 * than invent one.
 */
interface MarketPriceProvider
{
    /** Rial per fine gram, or null when no usable price exists. */
    public function currentPricePerFineGram(): ?int;

    /** Rial per fine gram at the close of the given Y-m-d date, or null. */
    public function closingPricePerFineGram(string $date): ?int;
}
