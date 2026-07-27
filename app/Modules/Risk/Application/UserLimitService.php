<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application;

use App\Modules\Risk\Infrastructure\Models\UserLimit;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Support\Facades\DB;

/**
 * Per-operator ceilings — check 10 of docs/03-domain/11-risk-credit.md §11.4,
 * exposed as `PUT /organization/users/{id}/limits` (§2.2).
 *
 * The endpoint's path lives under `/organization`, but `user_limits` is a Risk
 * table and the ceiling it holds is enforced by RiskGuard, so the write belongs
 * to this module. Identity owns *who* the user is; Risk owns *how much* they
 * may move.
 *
 * `max_order_mg` and `max_daily_volume_mg` are NOT NULL in the schema, while
 * the API accepts null for them. Null on a first write therefore means "no
 * ceiling of my own beyond the member's", which is stored as the member-level
 * figure taken from the risk profile — a per-user row that is looser than the
 * member's would be meaningless, since RiskGuard checks both.
 */
final class UserLimitService
{
    public function __construct(private readonly RiskProfileService $profiles) {}

    /**
     * Create or replace one operator's ceiling.
     *
     * The caller has already established that `$userId` belongs to
     * `$organizationId`; this method re-asserts it on the stored row so a user
     * that was moved between members cannot have a stale limit rewritten by the
     * wrong owner.
     *
     * @param  array<string, mixed>  $attributes  validated payload, keys limited to
     *                                            max_order_mg, max_daily_volume_mg,
     *                                            requires_approval_above_mg, is_active
     */
    public function set(int $organizationId, int $userId, array $attributes): UserLimit
    {
        return DB::transaction(function () use ($organizationId, $userId, $attributes): UserLimit {
            /** @var UserLimit|null $existing */
            $existing = UserLimit::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && (int) $existing->organization_id !== $organizationId) {
                throw new OperationNotPermittedException('user_limit_belongs_to_another_organization');
            }

            $profile = $this->profiles->profile($organizationId);

            $maxOrderMg = $this->resolve(
                $attributes,
                'max_order_mg',
                $existing?->max_order_mg ?? $profile->max_order_mg,
                $profile->max_order_mg,
            );

            $maxDailyVolumeMg = $this->resolve(
                $attributes,
                'max_daily_volume_mg',
                $existing?->max_daily_volume_mg ?? $profile->max_daily_volume_mg,
                $profile->max_daily_volume_mg,
            );

            // The only genuinely nullable column: null means "never ask for a
            // second approval", which is not the same as "ask above zero".
            $requiresApprovalAbove = array_key_exists('requires_approval_above_mg', $attributes)
                ? ($attributes['requires_approval_above_mg'] === null
                    ? null
                    : (int) $attributes['requires_approval_above_mg'])
                : $existing?->requires_approval_above_mg;

            $isActive = array_key_exists('is_active', $attributes)
                ? (bool) $attributes['is_active']
                : ($existing?->is_active ?? true);

            $values = [
                'organization_id' => $organizationId,
                'max_order_mg' => $maxOrderMg,
                'max_daily_volume_mg' => $maxDailyVolumeMg,
                'requires_approval_above_mg' => $requiresApprovalAbove,
                'is_active' => $isActive,
            ];

            if ($existing !== null) {
                $existing->forceFill($values)->save();

                return $existing;
            }

            /** @var UserLimit $created */
            $created = UserLimit::query()->create($values + ['user_id' => $userId]);

            return $created;
        });
    }

    /** The active row for one operator, or null when the member ceiling is the only one. */
    public function forUser(int $organizationId, int $userId): ?UserLimit
    {
        /** @var UserLimit|null $limit */
        $limit = UserLimit::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->first();

        return $limit;
    }

    /**
     * Absent key keeps `$current`; an explicit null falls back to the member
     * ceiling, because the column cannot hold null.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function resolve(array $attributes, string $key, int $current, int $memberCeiling): int
    {
        if (! array_key_exists($key, $attributes)) {
            return $current;
        }

        return $attributes[$key] === null ? $memberCeiling : (int) $attributes[$key];
    }
}
