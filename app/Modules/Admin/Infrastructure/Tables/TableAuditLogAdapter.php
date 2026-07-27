<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Tables;

use App\Modules\Admin\Contracts\AuditLogPort;
use App\Modules\Admin\Contracts\AuditRow;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Search over `audit_logs` for the viewer screen.
 *
 * Reads only. `audit_logs` is append-only (docs/02-architecture/04-security.md
 * §4.7) and in production the application database user has no UPDATE or DELETE
 * grant on it at all, so there is nothing to write here even if a route wanted
 * to.
 */
final class TableAuditLogAdapter implements AuditLogPort
{
    /** @return list<AuditRow> */
    public function search(array $filters, int $limit = 100, int $offset = 0): array
    {
        if (! Schema::hasTable('audit_logs')) {
            return [];
        }

        $query = $this->applyFilters(DB::table('audit_logs as a'), $filters)->select('a.*');

        if (Schema::hasTable('users')) {
            $query->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
                ->addSelect('u.full_name as actor_name');
        }

        $rows = $query->orderByDesc('a.id')->limit($limit)->offset($offset)->get();

        return array_map(static fn (object $row): AuditRow => new AuditRow(
            id: (int) $row->id,
            occurredAt: (string) $row->occurred_at,
            actorType: (string) $row->actor_type,
            actorId: $row->actor_id === null ? null : (int) $row->actor_id,
            actorName: isset($row->actor_name) && $row->actor_name !== null
                ? (string) $row->actor_name
                : null,
            organizationId: $row->organization_id === null ? null : (int) $row->organization_id,
            action: (string) $row->action,
            subjectType: $row->subject_type === null ? null : (string) $row->subject_type,
            subjectId: $row->subject_id === null ? null : (int) $row->subject_id,
            result: (string) $row->result,
            failureReason: $row->failure_reason === null ? null : (string) $row->failure_reason,
        ), $rows->all());
    }

    public function count(array $filters): int
    {
        if (! Schema::hasTable('audit_logs')) {
            return 0;
        }

        return (int) $this->applyFilters(DB::table('audit_logs as a'), $filters)->count();
    }

    /** @return list<string> */
    public function actions(int $limit = 200): array
    {
        if (! Schema::hasTable('audit_logs')) {
            return [];
        }

        return array_map(
            static fn (mixed $value): string => (string) $value,
            DB::table('audit_logs')->distinct()->orderBy('action')->limit($limit)->pluck('action')->all(),
        );
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['actor_id'])) {
            $query->where('a.actor_id', (int) $filters['actor_id']);
        }

        if (! empty($filters['organization_id'])) {
            $query->where('a.organization_id', (int) $filters['organization_id']);
        }

        if (! empty($filters['subject_type'])) {
            $query->where('a.subject_type', (string) $filters['subject_type']);
        }

        if (! empty($filters['subject_id'])) {
            $query->where('a.subject_id', (int) $filters['subject_id']);
        }

        if (! empty($filters['action'])) {
            // Prefix match, so searching "kyc." finds every KYC action without
            // the operator having to know the full dotted name.
            $query->where('a.action', 'like', str_replace(['%', '_'], ['\%', '\_'], (string) $filters['action']).'%');
        }

        if (! empty($filters['result'])) {
            $query->where('a.result', (string) $filters['result']);
        }

        if (! empty($filters['from'])) {
            $query->where('a.occurred_at', '>=', (string) $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('a.occurred_at', '<=', (string) $filters['to']);
        }

        return $query;
    }
}
