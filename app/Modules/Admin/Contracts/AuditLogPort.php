<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

/**
 * Search over `audit_logs`. Append-only: this port has no write method beyond
 * what Shared\Audit\AuditRecorder already provides, and no delete at all.
 */
interface AuditLogPort
{
    /**
     * @param  array{actor_id?: ?int, subject_type?: ?string, subject_id?: ?int, action?: ?string, organization_id?: ?int, result?: ?string, from?: ?string, to?: ?string}  $filters
     * @return list<AuditRow>
     */
    public function search(array $filters, int $limit = 100, int $offset = 0): array;

    /** @param array<string, mixed> $filters */
    public function count(array $filters): int;

    /** @return list<string> distinct action names, for the filter dropdown */
    public function actions(int $limit = 200): array;
}
