<?php

declare(strict_types=1);

namespace App\Modules\Custody\Infrastructure\Repositories;

use App\Modules\Custody\Contracts\AssayReaderInterface;
use App\Modules\Custody\Contracts\DTO\AssaySnapshot;
use App\Modules\Custody\Domain\Enums\AssayStatus;
use App\Modules\Custody\Infrastructure\Models\AssayModel;

/** Read-only view of assay certificates for the rest of the platform. */
final class EloquentAssayReader implements AssayReaderInterface
{
    public function find(int $assayId): ?AssaySnapshot
    {
        return AssayModel::query()->with('laboratory')->find($assayId)?->toSnapshot();
    }

    public function findByCode(string $assayCode): ?AssaySnapshot
    {
        return AssayModel::query()
            ->with('laboratory')
            ->where('assay_code', $assayCode)
            ->first()
            ?->toSnapshot();
    }

    public function findByQrToken(string $qrToken): ?AssaySnapshot
    {
        return AssayModel::query()
            ->with('laboratory')
            ->where('qr_token', $qrToken)
            ->first()
            ?->toSnapshot();
    }

    public function currentForLot(int $lotId): ?AssaySnapshot
    {
        return AssayModel::query()
            ->with('laboratory')
            ->where('gold_lot_id', $lotId)
            ->where('status', AssayStatus::VALID->value)
            ->orderByDesc('id')
            ->first()
            ?->toSnapshot();
    }

    public function historyForLot(int $lotId): array
    {
        return AssayModel::query()
            ->with('laboratory')
            ->where('gold_lot_id', $lotId)
            ->orderByDesc('id')
            ->get()
            ->map(static fn (AssayModel $a): AssaySnapshot => $a->toSnapshot())
            ->values()
            ->all();
    }
}
