<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Contracts\OrganizationLifecycle;
use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Identity\Infrastructure\Models\Organization;

/**
 * Adapter exposing organization lifecycle control to other modules.
 *
 * Everything here already exists inside Identity; this class only narrows it to
 * the id-based surface declared in the contract, so callers such as Kyc never
 * hold an Identity Eloquent model.
 */
final readonly class OrganizationLifecycleService implements OrganizationLifecycle
{
    public function __construct(
        private OrganizationStateMachine $stateMachine,
    ) {}

    public function currentStatus(int $organizationId): OrganizationStatus
    {
        return $this->organization($organizationId)->status;
    }

    public function transition(
        int $organizationId,
        OrganizationStatus $target,
        ?int $actorUserId = null,
        ?string $reason = null,
        array $metadata = [],
    ): void {
        $this->stateMachine->transition(
            organization: $this->organization($organizationId),
            target: $target,
            actorUserId: $actorUserId,
            reason: $reason,
            metadata: $metadata,
        );
    }

    public function transitionBySystem(
        int $organizationId,
        OrganizationStatus $target,
        string $reason,
        array $metadata = [],
    ): void {
        $this->stateMachine->transitionBySystem(
            organization: $this->organization($organizationId),
            target: $target,
            reason: $reason,
            metadata: $metadata,
        );
    }

    private function organization(int $organizationId): Organization
    {
        /** @var Organization $organization */
        $organization = Organization::query()->findOrFail($organizationId);

        return $organization;
    }
}
