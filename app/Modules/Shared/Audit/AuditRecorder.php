<?php

declare(strict_types=1);

namespace App\Modules\Shared\Audit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Writes audit rows and maintains the tamper-evident hash chain.
 *
 * The chain lets a nightly job prove that no historical row was altered or
 * removed, which matters because a database administrator sits outside the
 * application's permission model.
 */
final class AuditRecorder
{
    public function record(
        string $action,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?array $before = null,
        ?array $after = null,
        string $result = 'success',
        ?string $failureReason = null,
        ?int $organizationId = null,
        ?int $actorId = null,
        string $actorType = 'user',
        array $metadata = [],
    ): AuditLog {
        $request = $this->currentRequest();
        $user = auth()->user();

        $occurredAt = now();
        $prevHash = $this->lastHash();

        $row = [
            'occurred_at' => $occurredAt,
            'actor_type' => $actorType,
            'actor_id' => $actorId ?? $user?->getAuthIdentifier(),
            'organization_id' => $organizationId ?? ($user->organization_id ?? null),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before_state' => $before,
            'after_state' => $after,
            'ip_address' => $request?->ip() ? inet_pton($request->ip()) : null,
            'user_agent' => $request?->userAgent(),
            'session_id' => $request?->attributes->get('session_id'),
            'request_id' => $request?->attributes->get('request_id'),
            'result' => $result,
            'failure_reason' => $failureReason,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'prev_hash' => $prevHash,
        ];

        $row['row_hash'] = $this->hash($row, $prevHash);

        return AuditLog::create($row);
    }

    /** Verify the whole chain; returns the id of the first broken row, or null. */
    public function verifyChain(): ?int
    {
        $prevHash = null;

        foreach (AuditLog::orderBy('id')->cursor() as $entry) {
            $expected = $this->hash($entry->getAttributes(), $prevHash);

            if ($entry->row_hash !== $expected || $entry->prev_hash !== $prevHash) {
                return (int) $entry->id;
            }

            $prevHash = $entry->row_hash;
        }

        return null;
    }

    private function hash(array $row, ?string $prevHash): string
    {
        $occurredAt = $row['occurred_at'];
        $occurredAt = $occurredAt instanceof \DateTimeInterface
            ? $occurredAt->format('Y-m-d H:i:s.u')
            : (string) $occurredAt;

        $after = $row['after_state'] ?? null;
        $after = is_string($after) ? $after : json_encode($after, JSON_UNESCAPED_UNICODE);

        return hash('sha256', implode('|', [
            $prevHash ?? '',
            $occurredAt,
            (string) ($row['actor_id'] ?? ''),
            (string) $row['action'],
            (string) ($row['subject_type'] ?? ''),
            (string) ($row['subject_id'] ?? ''),
            (string) $after,
        ]));
    }

    private function lastHash(): ?string
    {
        return DB::table('audit_logs')->orderByDesc('id')->value('row_hash');
    }

    private function currentRequest(): ?Request
    {
        return app()->bound('request') && app('request') instanceof Request
            ? app('request')
            : null;
    }
}
