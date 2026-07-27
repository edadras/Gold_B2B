<?php

declare(strict_types=1);

namespace App\Modules\Custody\Http\Resources;

use App\Modules\Custody\Infrastructure\Models\VaultModel;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * A vault the member may deposit into.
 *
 * PLATFORM REFERENCE DATA, NOT TENANT DATA — every organisation gets the same
 * rows, so this payload must stay free of anything member-specific. In
 * particular: no current occupancy, no box assignments, no holdings. The
 * insurance figures are here because they are what a member weighs when
 * choosing where to store metal, and they describe the operator's policy, not
 * anyone's balance.
 *
 * @mixin VaultModel
 */
final class VaultResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var VaultModel $vault */
        $vault = $this->resource;

        return [
            'id' => (int) $vault->id,
            'vault_code' => (string) $vault->vault_code,
            'name' => (string) $vault->name,
            'address' => $vault->address,
            'status' => (string) $vault->status,
            'accepts_deposits' => $vault->acceptsDeposits(),
            'insurer_name' => $vault->insurer_name,
            'coverage_amount_rial' => $vault->coverage_amount_rial === null
                ? null
                : (int) $vault->coverage_amount_rial,
            'coverage_expires_at' => $vault->coverage_expires_at?->toDateString(),
        ] + $this->display($request, [
            'coverage_amount_display' => Display::rial(
                $vault->coverage_amount_rial === null ? null : (int) $vault->coverage_amount_rial,
            ),
            'coverage_expires_at_jalali' => Display::jalali($vault->coverage_expires_at, withTime: false),
        ]);
    }
}
