<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Http\Resources;

use App\Modules\Counterparty\Contracts\CreditHeadroom;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * `PUT /counterparties/{orgId}/limits` (docs §2.11) — the limit plus what is
 * left of it, which is the only useful confirmation of a limit change.
 *
 * "Used" is the receivable side only (§10.5): a payable is the other side's
 * exposure, measured against the other side's limit, and counting it here would
 * make a member's own headroom shrink because somebody owes *them*.
 *
 * @mixin CreditHeadroom
 */
final class CreditHeadroomResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CreditHeadroom $headroom */
        $headroom = $this->resource;

        return $headroom->toArray() + $this->display($request, [
            'gold_limit_display' => Display::grams($headroom->goldLimitMg),
            'gold_used_display' => Display::grams($headroom->goldUsedMg),
            'gold_remaining_display' => Display::grams($headroom->goldRemainingMg),
            'rial_limit_display' => Display::rial($headroom->rialLimit),
            'rial_used_display' => Display::rial($headroom->rialUsed),
            'rial_remaining_display' => Display::rial($headroom->rialRemaining),
        ]);
    }
}
