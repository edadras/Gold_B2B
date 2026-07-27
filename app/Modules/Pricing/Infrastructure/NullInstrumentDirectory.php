<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Infrastructure;

use App\Modules\Pricing\Contracts\InstrumentDirectory;

/**
 * Default binding for a deployment slice without Trading.
 *
 * Every code is unknown, so the market endpoints answer 404 rather than
 * fatally failing to resolve a dependency.
 */
final class NullInstrumentDirectory implements InstrumentDirectory
{
    public function idForCode(string $code): ?int
    {
        return null;
    }

    public function codeForId(int $instrumentId): ?string
    {
        return null;
    }

    public function activeInstrumentIds(): array
    {
        return [];
    }
}
