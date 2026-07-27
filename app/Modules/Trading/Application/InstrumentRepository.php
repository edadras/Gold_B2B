<?php

declare(strict_types=1);

namespace App\Modules\Trading\Application;

use App\Modules\Trading\Domain\Exceptions\TradingEntityNotFoundException;
use App\Modules\Trading\Domain\InstrumentStatus;
use App\Modules\Trading\Infrastructure\Models\Instrument;

/**
 * Instruments change roughly never, and every order placement needs one, so
 * they are memoised for the life of the request rather than re-read per order.
 */
final class InstrumentRepository
{
    /** @var array<string, Instrument> */
    private array $byCode = [];

    /** @var array<int, Instrument> */
    private array $byId = [];

    public function findByCode(string $code): ?Instrument
    {
        if (isset($this->byCode[$code])) {
            return $this->byCode[$code];
        }

        $instrument = Instrument::query()->where('code', $code)->first();

        if ($instrument !== null) {
            $this->remember($instrument);
        }

        return $instrument;
    }

    public function findByCodeOrFail(string $code): Instrument
    {
        return $this->findByCode($code) ?? throw TradingEntityNotFoundException::instrument($code);
    }

    public function find(int $id): ?Instrument
    {
        if (isset($this->byId[$id])) {
            return $this->byId[$id];
        }

        $instrument = Instrument::query()->find($id);

        if ($instrument !== null) {
            $this->remember($instrument);
        }

        return $instrument;
    }

    public function findOrFail(int $id): Instrument
    {
        return $this->find($id) ?? throw TradingEntityNotFoundException::instrument('#'.$id);
    }

    /** @return list<int> */
    public function activeIds(): array
    {
        return Instrument::query()
            ->where('status', InstrumentStatus::ACTIVE->value)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /** @return list<Instrument> */
    public function active(): array
    {
        return Instrument::query()
            ->where('status', InstrumentStatus::ACTIVE->value)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** Drops the memo — the console commands run long enough to need it. */
    public function forget(): void
    {
        $this->byCode = [];
        $this->byId = [];
    }

    private function remember(Instrument $instrument): void
    {
        $this->byCode[$instrument->code] = $instrument;
        $this->byId[$instrument->id] = $instrument;
    }
}
