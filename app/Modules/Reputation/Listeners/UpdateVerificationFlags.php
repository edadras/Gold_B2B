<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Listeners;

use App\Modules\Reputation\Application\StatsUpdater;

/**
 * Mirrors verification milestones into the tier inputs.
 *
 * Only booleans cross this boundary: whether a check passed, never what it
 * contained. §14.2 is explicit that no KYC or AML content may reach the
 * reputation surface, and the safest way to honour that is to never carry it.
 */
final class UpdateVerificationFlags
{
    private const KYC_APPROVED = 'App\Modules\Kyc\Events\KycApproved';

    /**
     * Bank accounts live in Kyc, not Identity, and nothing verifies one yet:
     * the column exists, the API reports it, no code writes it. Kept — and
     * pointed at the right module — so that the day Kyc emits this, the flag
     * starts moving with no change here. Deliberately NOT registered in
     * ReputationServiceProvider, so it is not mistaken for live wiring.
     */
    private const BANK_VERIFIED = 'App\Modules\Kyc\Events\BankAccountVerified';

    public function __construct(private readonly StatsUpdater $stats) {}

    public function handle(object $event): void
    {
        if (! property_exists($event, 'organizationId')) {
            return;
        }

        $organizationId = (int) $event->organizationId;

        if ($organizationId <= 0) {
            return;
        }

        $class = $event::class;

        if ($class === self::BANK_VERIFIED) {
            $this->stats->setVerification($organizationId, bankAccountVerified: true);

            return;
        }

        if ($class !== self::KYC_APPROVED) {
            return;
        }

        // A "full" KYC approval implies the basic one; a basic approval says
        // nothing about the full check.
        $level = property_exists($event, 'level') ? strtoupper((string) $event->level) : 'BASIC';
        $isFull = $level === 'FULL' || $level === 'ENHANCED';

        $this->stats->setVerification(
            $organizationId,
            kycBasicVerified: true,
            kycFullVerified: $isFull ? true : null,
        );

        if (property_exists($event, 'memberSince')) {
            $this->stats->ensure($organizationId, (string) $event->memberSince);
        }
    }
}
