<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Domain\AuthorityType;
use App\Modules\Identity\Infrastructure\Models\Representative;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * An authorised representative. The national id stays encrypted and unexported.
 *
 * @mixin Representative
 */
final class RepresentativeResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Representative $representative */
        $representative = $this->resource;

        return [
            'id' => (int) $representative->id,
            'organization_id' => (int) $representative->organization_id,
            'user_id' => $representative->user_id === null ? null : (int) $representative->user_id,
            'full_name' => (string) $representative->full_name,
            'authority_type' => $representative->authority_type->value,
            'daily_limit_mg' => $representative->daily_limit_mg === null
                ? null
                : (int) $representative->daily_limit_mg,
            'status' => $representative->status->value,
            'valid_from' => $representative->valid_from?->toDateString(),
            'valid_until' => $representative->valid_until?->toDateString(),
            'document_id' => $representative->document_id === null ? null : (int) $representative->document_id,
            'is_effective_for_trade' => $representative->isEffective(AuthorityType::TRADE),
        ] + $this->display($request, [
            'daily_limit_display' => Display::grams($representative->daily_limit_mg),
            'valid_from_jalali' => Display::jalali($representative->valid_from, withTime: false),
            'valid_until_jalali' => Display::jalali($representative->valid_until, withTime: false),
        ]);
    }
}
