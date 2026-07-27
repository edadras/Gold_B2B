<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application;

use App\Modules\Pricing\Domain\AlertCondition;
use App\Modules\Pricing\Domain\AlertStatus;
use App\Modules\Pricing\Infrastructure\Models\PriceAlert;
use Illuminate\Database\Eloquent\Collection;

/**
 * CRUD for a member's own price alerts (`/price-alerts`, §2.4).
 *
 * PriceAlertService owns evaluation and throttling; this owns the list the
 * member manages. Splitting them keeps the hot evaluation path free of
 * presentation concerns, and keeps this class out of the ingestion loop.
 *
 * Alerts belong to an organisation AND to the user who created them, and every
 * query here filters on both — one trader's alerts are not another's business
 * even inside the same member.
 */
final class PriceAlertManager
{
    public const MAX_PER_USER = 50;

    /** @return Collection<int, PriceAlert> */
    public function forUser(int $organizationId, int $userId): Collection
    {
        /** @var Collection<int, PriceAlert> */
        return PriceAlert::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->get();
    }

    public function create(
        int $organizationId,
        int $userId,
        int $instrumentId,
        AlertCondition $condition,
        int $threshold,
        int $windowSeconds = 3600,
        bool $isRecurring = false,
    ): PriceAlert {
        /** @var PriceAlert $alert */
        $alert = PriceAlert::query()->create([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'instrument_id' => $instrumentId,
            'condition' => $condition,
            'threshold' => $threshold,
            'window_seconds' => $windowSeconds,
            'is_recurring' => $isRecurring,
            'status' => AlertStatus::ACTIVE,
        ]);

        return $alert;
    }

    public function countFor(int $organizationId, int $userId): int
    {
        return PriceAlert::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('status', AlertStatus::ACTIVE->value)
            ->count();
    }

    /** Null when the alert is missing or belongs to someone else. */
    public function find(int $alertId, int $organizationId, int $userId): ?PriceAlert
    {
        /** @var PriceAlert|null */
        return PriceAlert::query()
            ->where('id', $alertId)
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->first();
    }

    public function delete(PriceAlert $alert): void
    {
        $alert->delete();
    }
}
