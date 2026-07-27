<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Shared\Exceptions\InvalidStateTransitionException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The slice of organization lifecycle control other modules are allowed to use.
 *
 * Kyc in particular must move an organization to VERIFIED and then ACTIVE when a
 * review is approved, and RESTRICTED when a licence lapses. Exposing that here
 * keeps Kyc off Identity's Eloquent models and application services, which is
 * what the module boundary rule requires — see docs/02-architecture/02-modules.md §2.3.
 *
 * Deliberately write-only apart from `currentStatus`: reads about an
 * organization belong on IdentityDirectory, which returns snapshot DTOs. This
 * contract exists so a caller can *act*, and every action it exposes still runs
 * through OrganizationStateMachine — validated, recorded and event-emitting.
 */
interface OrganizationLifecycle
{
    /** @throws ModelNotFoundException when no such organization exists */
    public function currentStatus(int $organizationId): OrganizationStatus;

    /**
     * Move an organization to a new status on behalf of a human actor.
     *
     * `$actorUserId` is nullable because a member's own self-service actions
     * (submitting a KYC dossier from an unauthenticated resume link, a job
     * acting on the member's behalf) have no signed-in user to attribute; the
     * transition is then recorded with a null actor but still as actor_type
     * USER, distinct from transitionBySystem().
     *
     * @param  array<string, mixed>  $metadata
     *
     * @throws InvalidStateTransitionException
     */
    public function transition(
        int $organizationId,
        OrganizationStatus $target,
        ?int $actorUserId = null,
        ?string $reason = null,
        array $metadata = [],
    ): void;

    /**
     * Move an organization automatically; a reason is mandatory for the audit
     * trail, and the row is stamped actor_type SYSTEM.
     *
     * @param  array<string, mixed>  $metadata
     *
     * @throws InvalidStateTransitionException
     */
    public function transitionBySystem(
        int $organizationId,
        OrganizationStatus $target,
        string $reason,
        array $metadata = [],
    ): void;
}
