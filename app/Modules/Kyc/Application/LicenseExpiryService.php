<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Application;

use App\Modules\Identity\Application\OrganizationStateMachine;
use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Kyc\Domain\LicenseStatus;
use App\Modules\Kyc\Events\LicenseExpired;
use App\Modules\Kyc\Events\LicenseExpiring;
use App\Modules\Kyc\Infrastructure\Models\BusinessLicense;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * The reminder ladder and the automatic restriction of docs §1.6:
 *
 *     30 days out  ► in-app notification + email
 *     10 days out  ► notification + SMS
 *      1 day  out  ► notification + SMS + dashboard warning
 *     expired      ► organisation ► RESTRICTED
 *                    (open orders cancelled, settlement only, no new trades)
 *
 * Choosing channels is the notification module's job. This service decides
 * *when* and emits LicenseExpiring / LicenseExpired.
 *
 * Idempotent: each rung sets its own `reminder_sent_*` flag, so the command may
 * run as often as it likes without duplicating notifications.
 */
final class LicenseExpiryService
{
    /** Descending, because a licence 5 days out must fire the 10-day rung once. */
    public const REMINDER_LADDER_DAYS = [30, 10, 1];

    public function __construct(
        private readonly OrganizationStateMachine $organizationState,
    ) {}

    /**
     * @return array{reminders: int, expired: int, restricted: int}
     */
    public function run(?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();

        return [
            'reminders' => $this->sendReminders($asOf),
            ...$this->enforceExpiries($asOf),
        ];
    }

    /** @return int number of reminder events dispatched */
    public function sendReminders(?Carbon $asOf = null): int
    {
        $asOf ??= Carbon::now();
        $sent = 0;

        $horizon = $asOf->copy()->startOfDay()->addDays(max(self::REMINDER_LADDER_DAYS));

        /** @var iterable<BusinessLicense> $licenses */
        $licenses = BusinessLicense::query()
            ->usable()
            ->whereDate('expires_at', '>=', $asOf->copy()->startOfDay())
            ->whereDate('expires_at', '<=', $horizon)
            ->orderBy('id')
            ->cursor();

        foreach ($licenses as $license) {
            $daysRemaining = $license->daysUntilExpiry($asOf);
            $rung = $this->rungFor($daysRemaining);

            if ($rung === null) {
                continue;
            }

            $flag = "reminder_sent_{$rung}d";

            if ((bool) $license->{$flag}) {
                continue;
            }

            $license->{$flag} = true;
            $license->status = LicenseStatus::EXPIRING_SOON;
            $license->save();

            Event::dispatch(new LicenseExpiring(
                organizationId: (int) $license->organization_id,
                businessLicenseId: (int) $license->id,
                licenseNo: (string) $license->license_no,
                daysRemaining: $daysRemaining,
                expiresAt: $license->expires_at->toDateString(),
                occurredAt: $asOf->toIso8601String(),
            ));

            $sent++;
        }

        return $sent;
    }

    /**
     * Mark lapsed licences EXPIRED and restrict the member.
     *
     * @return array{expired: int, restricted: int}
     */
    public function enforceExpiries(?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();
        $expired = 0;
        $restricted = 0;

        /** @var iterable<BusinessLicense> $licenses */
        $licenses = BusinessLicense::query()
            ->where('expiry_enforced', false)
            ->whereIn('status', [
                LicenseStatus::PENDING->value,
                LicenseStatus::VALID->value,
                LicenseStatus::EXPIRING_SOON->value,
            ])
            ->whereDate('expires_at', '<', $asOf->copy()->startOfDay())
            ->orderBy('id')
            ->cursor();

        foreach ($licenses as $license) {
            $organization = Organization::query()->find($license->organization_id);

            if ($organization === null) {
                continue;
            }

            // A member with another still-valid licence keeps trading.
            $hasValidAlternative = BusinessLicense::query()
                ->where('organization_id', $license->organization_id)
                ->whereKeyNot($license->id)
                ->usable()
                ->whereDate('expires_at', '>=', $asOf->copy()->startOfDay())
                ->exists();

            DB::transaction(function () use ($license): void {
                $license->status = LicenseStatus::EXPIRED;
                $license->expiry_enforced = true;
                $license->save();
            });

            $expired++;
            $didRestrict = false;

            if (! $hasValidAlternative
                && $organization->status->canTransitionTo(OrganizationStatus::RESTRICTED)) {
                $this->organizationState->transitionBySystem(
                    organization: $organization,
                    target: OrganizationStatus::RESTRICTED,
                    reason: 'انقضای جواز کسب شماره '.$license->license_no,
                    metadata: ['business_license_id' => (int) $license->id],
                );

                $restricted++;
                $didRestrict = true;
            }

            Event::dispatch(new LicenseExpired(
                organizationId: (int) $license->organization_id,
                businessLicenseId: (int) $license->id,
                licenseNo: (string) $license->license_no,
                expiredAt: $license->expires_at->toDateString(),
                organizationRestricted: $didRestrict,
                occurredAt: $asOf->toIso8601String(),
            ));
        }

        return ['expired' => $expired, 'restricted' => $restricted];
    }

    /**
     * Lowest ladder rung that a licence this close to expiry has reached.
     * 25 days out → the 30-day rung; 5 days out → the 10-day rung.
     */
    private function rungFor(int $daysRemaining): ?int
    {
        if ($daysRemaining < 0) {
            return null;
        }

        // The tightest applicable rung wins, so a licence that first appears
        // 5 days out fires the 10-day rung and then the 1-day rung, rather than
        // silently skipping straight past both.
        $applicable = null;

        foreach (self::REMINDER_LADDER_DAYS as $rung) {
            if ($daysRemaining <= $rung && ($applicable === null || $rung < $applicable)) {
                $applicable = $rung;
            }
        }

        return $applicable;
    }
}
