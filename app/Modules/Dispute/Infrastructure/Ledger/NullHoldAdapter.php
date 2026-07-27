<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Infrastructure\Ledger;

use App\Modules\Dispute\Contracts\DisputeHoldPort;
use Illuminate\Support\Facades\Log;

/**
 * Fallback used when no ledger implementation is bound.
 *
 * A dispute must still be openable — the case file, the deadlines and the
 * timeline have value on their own — but the absence of a hold has to be
 * visible rather than silent, so every skipped operation is logged and
 * isOperational() answers false. Anything that needs to know whether the money
 * is genuinely frozen asks that question instead of assuming it.
 */
final class NullHoldAdapter implements DisputeHoldPort
{
    public function holdGold(int $organizationId, int $fineMg, int $disputeId): ?int
    {
        $this->warn('holdGold', $disputeId, $fineMg);

        return null;
    }

    public function holdRial(int $organizationId, int $rial, int $disputeId): ?int
    {
        $this->warn('holdRial', $disputeId, $rial);

        return null;
    }

    public function releaseGoldHold(int $organizationId, int $fineMg, int $disputeId, ?int $entryId): void
    {
        // Nothing was ever held, so there is nothing to release.
    }

    public function releaseRialHold(int $organizationId, int $rial, int $disputeId, ?int $entryId): void
    {
        // Nothing was ever held, so there is nothing to release.
    }

    public function transferGold(int $fromOrgId, int $toOrgId, int $fineMg, int $disputeId): void
    {
        $this->warn('transferGold', $disputeId, $fineMg);
    }

    public function transferRial(int $fromOrgId, int $toOrgId, int $rial, int $disputeId): void
    {
        $this->warn('transferRial', $disputeId, $rial);
    }

    public function isOperational(): bool
    {
        return false;
    }

    private function warn(string $operation, int $disputeId, int $amount): void
    {
        Log::channel(config('logging.default'))->warning('dispute: no ledger bound, operation skipped', [
            'operation' => $operation,
            'dispute_id' => $disputeId,
            'amount' => $amount,
        ]);
    }
}
