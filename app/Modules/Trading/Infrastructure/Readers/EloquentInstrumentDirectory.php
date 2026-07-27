<?php

declare(strict_types=1);

namespace App\Modules\Trading\Infrastructure\Readers;

use App\Modules\Pricing\Contracts\InstrumentDirectory;
use App\Modules\Trading\Application\InstrumentRepository;

/**
 * Trading's answer to the port Pricing declared.
 *
 * Delegates to InstrumentRepository, which memoises for the life of the
 * request — the market-data endpoints resolve the same code repeatedly.
 */
final readonly class EloquentInstrumentDirectory implements InstrumentDirectory
{
    public function __construct(private InstrumentRepository $instruments) {}

    public function idForCode(string $code): ?int
    {
        $id = $this->instruments->findByCode($code)?->id;

        return $id === null ? null : (int) $id;
    }

    public function codeForId(int $instrumentId): ?string
    {
        $code = $this->instruments->find($instrumentId)?->code;

        return $code === null ? null : (string) $code;
    }

    public function activeInstrumentIds(): array
    {
        return $this->instruments->activeIds();
    }
}
