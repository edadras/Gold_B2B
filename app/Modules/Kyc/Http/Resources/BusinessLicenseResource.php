<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Http\Resources;

use App\Modules\Kyc\Infrastructure\Models\BusinessLicense;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * A guild trading licence.
 *
 * `days_until_expiry` is exported as an integer because the client drives the
 * 30/10/1-day warning banner off it, and a date subtraction on the client
 * would disagree with the server's Tehran-midnight arithmetic.
 *
 * @mixin BusinessLicense
 */
final class BusinessLicenseResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var BusinessLicense $license */
        $license = $this->resource;

        return [
            'id' => (int) $license->id,
            'organization_id' => (int) $license->organization_id,
            'license_no' => (string) $license->license_no,
            'issuing_union' => (string) $license->issuing_union,
            'activity_type' => $license->activity_type,
            'premises_address' => $license->premises_address,
            'status' => $license->status->value,
            'is_usable' => $license->status->isUsable(),
            'is_expired' => $license->isExpired(),
            'days_until_expiry' => $license->daysUntilExpiry(),
            'document_id' => $license->document_id === null ? null : (int) $license->document_id,
            'issued_at' => $license->issued_at?->toDateString(),
            'expires_at' => $license->expires_at?->toDateString(),
            'verified_at' => Display::iso($license->verified_at),
        ] + $this->display($request, [
            'issued_at_jalali' => Display::jalali($license->issued_at, withTime: false),
            'expires_at_jalali' => Display::jalali($license->expires_at, withTime: false),
            'verified_at_jalali' => Display::jalali($license->verified_at),
        ]);
    }
}
