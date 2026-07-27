<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application;

use App\Modules\Admin\Contracts\AuditLogPort;
use App\Modules\Admin\Contracts\AuditRow;

/**
 * The audit log viewer (§1.8). Search by actor, subject and action — the three
 * questions an investigation actually starts from.
 *
 * There is no write path and no delete path, by design: `audit_logs` is
 * append-only and in production the application's database user holds no
 * UPDATE or DELETE grant on it (docs/02-architecture/04-security.md §4.7).
 */
final class AuditLogService
{
    public function __construct(private readonly AuditLogPort $auditLog) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<AuditRow>, total: int, page: int, perPage: int}
     */
    public function search(array $filters, int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 200));

        return [
            'rows' => $this->auditLog->search($filters, $perPage, ($page - 1) * $perPage),
            'total' => $this->auditLog->count($filters),
            'page' => $page,
            'perPage' => $perPage,
        ];
    }

    /** @return list<string> */
    public function actions(): array
    {
        return $this->auditLog->actions();
    }
}
