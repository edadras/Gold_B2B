<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * The caller's own organisation.
 *
 * The national id and legal id are deliberately absent even in ciphertext form:
 * the client never needs them and the endpoint is reachable by every role in
 * the member, including VIEWER.
 *
 * `verification_tier` is quoted in the docs' login sample but is owned by the
 * Reputation module, which Identity may not import. It is merged in by the
 * controller through a contract when Reputation is present; here it is
 * accepted as an optional extra rather than read from the model.
 *
 * @mixin Organization
 */
final class OrganizationResource extends ApiResource
{
    /** @param array<string, mixed> $extra */
    public function __construct(mixed $resource, private readonly array $extra = [])
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Organization $organization */
        $organization = $this->resource;

        return [
            'id' => (int) $organization->id,
            'type' => $organization->type->value,
            'status' => $organization->status->value,
            'display_name' => (string) $organization->display_name,
            'legal_name' => $organization->legal_name,
            'registration_no' => $organization->registration_no,
            'union_name' => $organization->union_name,
            'city' => (string) $organization->city,
            'province' => $organization->province,
            'market_name' => $organization->market_name,
            'address' => $organization->address,
            'postal_code' => $organization->postal_code,
            'phone' => $organization->phone,
            'mobile' => (string) $organization->mobile,
            'email' => $organization->email,
            'website' => $organization->website,
            'risk_level' => $organization->risk_level->value,
            'compliance_state' => $organization->compliance_state?->value,
            'established_at' => $organization->established_at?->toDateString(),
            'activated_at' => Display::iso($organization->activated_at),
            'can_trade' => $organization->status->canTrade(),
            'can_settle' => $organization->status->canSettle(),
        ] + $this->extra + $this->display($request, [
            'established_at_jalali' => Display::jalali($organization->established_at, withTime: false),
            'activated_at_jalali' => Display::jalali($organization->activated_at),
        ]);
    }

    /** The two-field form embedded in the login response and in /auth/me. */
    public static function summary(Organization $organization, ?string $verificationTier = null): array
    {
        return array_filter([
            'id' => (int) $organization->id,
            'display_name' => (string) $organization->display_name,
            'status' => $organization->status->value,
            'type' => $organization->type->value,
            'verification_tier' => $verificationTier,
        ], static fn ($v) => $v !== null);
    }
}
