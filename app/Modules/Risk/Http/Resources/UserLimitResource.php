<?php

declare(strict_types=1);

namespace App\Modules\Risk\Http\Resources;

use App\Modules\Risk\Infrastructure\Models\UserLimit;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * One operator's ceiling — `PUT /organization/users/{id}/limits` (§2.2).
 *
 * @mixin UserLimit
 */
final class UserLimitResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var UserLimit $limit */
        $limit = $this->resource;

        return [
            'id' => (int) $limit->id,
            'organization_id' => (int) $limit->organization_id,
            'user_id' => (int) $limit->user_id,
            'max_order_mg' => $limit->max_order_mg,
            'max_daily_volume_mg' => $limit->max_daily_volume_mg,
            'requires_approval_above_mg' => $limit->requires_approval_above_mg,
            'is_active' => $limit->is_active,
        ] + $this->display($request, [
            'max_order_display' => Display::grams($limit->max_order_mg),
            'max_daily_volume_display' => Display::grams($limit->max_daily_volume_mg),
            'requires_approval_above_display' => Display::grams($limit->requires_approval_above_mg),
        ]);
    }
}
