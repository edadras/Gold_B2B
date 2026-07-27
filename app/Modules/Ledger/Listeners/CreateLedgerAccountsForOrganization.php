<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Listeners;

use App\Modules\Ledger\Application\AccountProvisioner;
use Illuminate\Support\Facades\Log;

/**
 * Provisions a member's ledger accounts when Identity activates them.
 *
 * Listens for App\Modules\Identity\Events\OrganizationActivated, which does not
 * exist yet — the provider registers the listener against the class *name*, so
 * nothing here loads that class. The event is read defensively for the same
 * reason: whatever shape Identity settles on, the organisation id is the only
 * thing this listener needs.
 */
final class CreateLedgerAccountsForOrganization
{
    public function __construct(private readonly AccountProvisioner $provisioner) {}

    public function handle(object $event): void
    {
        $organizationId = $this->organizationIdOf($event);

        if ($organizationId === null) {
            Log::warning('OrganizationActivated carried no organisation id; no ledger accounts created', [
                'event' => $event::class,
            ]);

            return;
        }

        $ids = $this->provisioner->provisionMember($organizationId);

        Log::info('Ledger accounts provisioned', [
            'organization_id' => $organizationId,
            'account_count' => count($ids),
        ]);
    }

    private function organizationIdOf(object $event): ?int
    {
        foreach (['organizationId', 'organization_id', 'orgId', 'id'] as $property) {
            if (property_exists($event, $property) && is_int($event->{$property})) {
                return $event->{$property};
            }
        }

        return null;
    }
}
