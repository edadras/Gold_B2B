<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Identity\Events\OrganizationActivated;
use App\Modules\Identity\Events\OrganizationRestricted;
use App\Modules\Identity\Events\OrganizationStatusChanged;
use App\Modules\Identity\Events\OrganizationSuspended;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Identity\Infrastructure\Models\OrganizationStatusEvent;
use App\Modules\Shared\Exceptions\InvalidStateTransitionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * The one and only way an organisation's status may change.
 *
 * Responsibilities, in order:
 *   1. reject anything the enum does not permit;
 *   2. apply the transition and its timestamp side effects under a row lock;
 *   3. append an immutable row to `organization_status_events`;
 *   4. dispatch the domain events — AFTER the transaction commits, per
 *      AGENT_BRIEF rule 3.
 *
 * Cancelling a restricted member's open orders is Trading's job. This class
 * emits OrganizationRestricted / OrganizationSuspended and stops there; it must
 * never touch another module's tables.
 */
final class OrganizationStateMachine
{
    public const ACTOR_USER = 'USER';

    public const ACTOR_SYSTEM = 'SYSTEM';

    /**
     * @param  array<string, mixed>  $metadata
     *
     * @throws InvalidStateTransitionException
     */
    public function transition(
        Organization $organization,
        OrganizationStatus $target,
        ?int $actorUserId = null,
        ?string $reason = null,
        array $metadata = [],
        string $actorType = self::ACTOR_USER,
    ): Organization {
        /** @var list<callable():void> $afterCommit */
        $afterCommit = [];

        $organization = DB::transaction(function () use (
            $organization, $target, $actorUserId, $reason, $metadata, $actorType, &$afterCommit
        ): Organization {
            /** @var Organization $locked */
            $locked = Organization::query()
                ->whereKey($organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $from = $locked->status;

            if ($from === $target) {
                throw new InvalidStateTransitionException(
                    'Organization', $from->value, $target->value,
                );
            }

            if (! $from->canTransitionTo($target)) {
                throw new InvalidStateTransitionException(
                    'Organization', $from->value, $target->value,
                );
            }

            $now = now();
            $locked->status = $target;
            $this->applyTimestamps($locked, $target, $reason);
            $locked->save();

            OrganizationStatusEvent::query()->create([
                'organization_id' => $locked->id,
                'from_status' => $from->value,
                'to_status' => $target->value,
                'actor_type' => $actorType,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'metadata' => $metadata === [] ? null : $metadata,
                'occurred_at' => $now,
            ]);

            $occurredAt = $now->toIso8601String();
            $organizationId = (int) $locked->id;
            $fromValue = $from->value;

            $afterCommit[] = static function () use (
                $organizationId, $fromValue, $target, $actorType, $actorUserId, $reason, $occurredAt
            ): void {
                Event::dispatch(new OrganizationStatusChanged(
                    organizationId: $organizationId,
                    fromStatus: $fromValue,
                    toStatus: $target->value,
                    actorType: $actorType,
                    actorUserId: $actorUserId,
                    reason: $reason,
                    occurredAt: $occurredAt,
                ));
            };

            if ($target === OrganizationStatus::ACTIVE) {
                $riskLevel = $locked->risk_level->value;
                $type = $locked->type->value;
                $afterCommit[] = static function () use (
                    $organizationId, $type, $riskLevel, $actorUserId, $occurredAt
                ): void {
                    Event::dispatch(new OrganizationActivated(
                        organizationId: $organizationId,
                        type: $type,
                        riskLevel: $riskLevel,
                        actorUserId: $actorUserId,
                        occurredAt: $occurredAt,
                    ));
                };
            }

            if ($target === OrganizationStatus::RESTRICTED) {
                $afterCommit[] = static function () use (
                    $organizationId, $fromValue, $reason, $actorUserId, $occurredAt
                ): void {
                    Event::dispatch(new OrganizationRestricted(
                        organizationId: $organizationId,
                        previousStatus: $fromValue,
                        reason: $reason ?? '',
                        actorUserId: $actorUserId,
                        occurredAt: $occurredAt,
                    ));
                };
            }

            if ($target === OrganizationStatus::SUSPENDED) {
                $afterCommit[] = static function () use (
                    $organizationId, $fromValue, $reason, $actorUserId, $occurredAt
                ): void {
                    Event::dispatch(new OrganizationSuspended(
                        organizationId: $organizationId,
                        previousStatus: $fromValue,
                        reason: $reason ?? '',
                        actorUserId: $actorUserId,
                        occurredAt: $occurredAt,
                    ));
                };
            }

            return $locked;
        });

        foreach ($afterCommit as $dispatch) {
            $dispatch();
        }

        return $organization;
    }

    /** Convenience wrapper for automatic, non-human transitions. */
    public function transitionBySystem(
        Organization $organization,
        OrganizationStatus $target,
        string $reason,
        array $metadata = [],
    ): Organization {
        return $this->transition(
            organization: $organization,
            target: $target,
            actorUserId: null,
            reason: $reason,
            metadata: $metadata,
            actorType: self::ACTOR_SYSTEM,
        );
    }

    /** Records the initial PENDING row for a freshly created organisation. */
    public function recordInitialState(Organization $organization, ?int $actorUserId = null): void
    {
        OrganizationStatusEvent::query()->create([
            'organization_id' => $organization->id,
            'from_status' => null,
            'to_status' => $organization->status->value,
            'actor_type' => $actorUserId === null ? self::ACTOR_SYSTEM : self::ACTOR_USER,
            'actor_user_id' => $actorUserId,
            'reason' => 'ثبت اولیه عضو',
            'metadata' => null,
            'occurred_at' => now(),
        ]);
    }

    public function canTransition(Organization $organization, OrganizationStatus $target): bool
    {
        return $organization->status->canTransitionTo($target);
    }

    private function applyTimestamps(
        Organization $organization,
        OrganizationStatus $target,
        ?string $reason,
    ): void {
        $now = now();

        switch ($target) {
            case OrganizationStatus::ACTIVE:
                // activated_at marks the *first* activation and is never reset,
                // so "member since" stays honest across suspend/reinstate.
                $organization->activated_at ??= $now;
                $organization->restriction_reason = null;
                break;

            case OrganizationStatus::RESTRICTED:
                $organization->restricted_at = $now;
                $organization->restriction_reason = $reason;
                break;

            case OrganizationStatus::SUSPENDED:
                $organization->suspended_at = $now;
                $organization->restriction_reason = $reason;
                break;

            case OrganizationStatus::CLOSED:
                $organization->closed_at = $now;
                break;

            default:
                break;
        }
    }
}
