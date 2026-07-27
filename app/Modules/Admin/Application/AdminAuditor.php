<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application;

use App\Modules\Shared\Audit\AuditRecorder;
use InvalidArgumentException;

/**
 * Every audit row the operator panel writes goes through here.
 *
 * Two of docs/08-frontend-web/01-web-panels.md §1.11's rules are enforced by
 * construction rather than by review:
 *
 *   · «هر مشاهده داده حساس در Audit ثبت شود» — viewSensitive() is the only way
 *     the panel is allowed to render a KYC document, a national id or another
 *     member's statement, and it writes before it returns;
 *   · «هر اقدام نیازمند یادداشت» — action() will not write a row without a
 *     non-empty note, so an action that forgets one fails loudly in tests
 *     rather than quietly in production.
 *
 * Actor type is always `platform_staff`: these rows come from the operator's
 * own employees, and telling them apart from member activity is the first
 * question anyone asks of an audit trail.
 */
final class AdminAuditor
{
    public const ACTOR_TYPE = 'platform_staff';

    public function __construct(private readonly AuditRecorder $recorder) {}

    /**
     * Record that a member of staff looked at sensitive data.
     *
     * @param  list<string>  $fields  which sensitive fields were revealed
     */
    public function viewSensitive(
        string $subjectType,
        int $subjectId,
        array $fields,
        ?int $organizationId = null,
        ?int $actorId = null,
    ): void {
        $this->recorder->record(
            action: 'admin.view_sensitive',
            subjectType: $subjectType,
            subjectId: $subjectId,
            organizationId: $organizationId,
            actorId: $actorId,
            actorType: self::ACTOR_TYPE,
            metadata: ['fields' => $fields],
        );
    }

    /**
     * Record a state-changing staff action.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $metadata
     */
    public function action(
        string $action,
        string $subjectType,
        ?int $subjectId,
        string $note,
        ?array $before = null,
        ?array $after = null,
        ?int $organizationId = null,
        ?int $actorId = null,
        array $metadata = [],
    ): void {
        if (trim($note) === '') {
            throw new InvalidArgumentException(
                "Admin action {$action} was recorded without a note; §1.11 requires one."
            );
        }

        $this->recorder->record(
            action: $action,
            subjectType: $subjectType,
            subjectId: $subjectId,
            before: $before,
            after: $after,
            organizationId: $organizationId,
            actorId: $actorId,
            actorType: self::ACTOR_TYPE,
            metadata: $metadata + ['note' => $note],
        );
    }

    /** A refused action is as interesting as a successful one. */
    public function denied(
        string $action,
        string $subjectType,
        ?int $subjectId,
        string $reason,
        ?int $actorId = null,
    ): void {
        $this->recorder->record(
            action: $action,
            subjectType: $subjectType,
            subjectId: $subjectId,
            result: 'denied',
            failureReason: mb_substr($reason, 0, 500),
            actorId: $actorId,
            actorType: self::ACTOR_TYPE,
        );
    }
}
