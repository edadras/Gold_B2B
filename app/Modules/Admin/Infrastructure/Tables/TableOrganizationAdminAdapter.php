<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Tables;

use App\Modules\Admin\Contracts\OrganizationAdminPort;
use App\Modules\Admin\Contracts\OrganizationRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Member listing for the organisation management screen.
 *
 * Deliberately projects a fixed column list. `national_id_enc` and
 * `legal_id_enc` are not in it, so a member list can never carry an encrypted
 * identifier into a view by accident — the strongest form of "do not leak" is
 * "never select".
 *
 * Status *changes* do not happen here: they go through Identity's
 * `OrganizationLifecycle`, which validates the transition, records the event
 * and dispatches the domain event.
 */
final class TableOrganizationAdminAdapter implements OrganizationAdminPort
{
    private const COLUMNS = [
        'id', 'display_name', 'type', 'status', 'risk_level', 'compliance_state',
        'city', 'restriction_reason', 'is_platform', 'created_at',
    ];

    /** @return list<OrganizationRow> */
    public function list(?string $search = null, array $statuses = [], int $limit = 100): array
    {
        if (! Schema::hasTable('organizations')) {
            return [];
        }

        $query = DB::table('organizations');

        if ($search !== null && trim($search) !== '') {
            $escaped = str_replace(['%', '_'], ['\%', '\_'], trim($search));

            $query->where(function ($q) use ($escaped): void {
                $q->where('display_name', 'like', '%'.$escaped.'%')
                    ->orWhere('legal_name', 'like', '%'.$escaped.'%')
                    ->orWhere('registration_no', 'like', $escaped.'%');
            });
        }

        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        $rows = $query->orderBy('display_name')->limit($limit)->get(self::COLUMNS);

        return array_map(static fn (object $row): OrganizationRow => new OrganizationRow(
            id: (int) $row->id,
            displayName: (string) $row->display_name,
            type: (string) $row->type,
            status: (string) $row->status,
            riskLevel: (string) $row->risk_level,
            complianceState: (string) $row->compliance_state,
            city: $row->city === null ? null : (string) $row->city,
            restrictionReason: $row->restriction_reason === null ? null : (string) $row->restriction_reason,
            isPlatform: (bool) $row->is_platform,
            createdAt: $row->created_at === null ? null : (string) $row->created_at,
        ), $rows->all());
    }

    public function find(int $organizationId): ?OrganizationRow
    {
        if (! Schema::hasTable('organizations')) {
            return null;
        }

        $row = DB::table('organizations')->where('id', $organizationId)->first(self::COLUMNS);

        if ($row === null) {
            return null;
        }

        return new OrganizationRow(
            id: (int) $row->id,
            displayName: (string) $row->display_name,
            type: (string) $row->type,
            status: (string) $row->status,
            riskLevel: (string) $row->risk_level,
            complianceState: (string) $row->compliance_state,
            city: $row->city === null ? null : (string) $row->city,
            restrictionReason: $row->restriction_reason === null ? null : (string) $row->restriction_reason,
            isPlatform: (bool) $row->is_platform,
            createdAt: $row->created_at === null ? null : (string) $row->created_at,
        );
    }

    public function countByStatus(string $status): int
    {
        if (! Schema::hasTable('organizations')) {
            return 0;
        }

        return (int) DB::table('organizations')
            ->where('status', $status)
            ->where('is_platform', false)
            ->count();
    }

    public function countRegisteredSince(string $since): int
    {
        if (! Schema::hasTable('organizations')) {
            return 0;
        }

        return (int) DB::table('organizations')
            ->where('created_at', '>=', $since)
            ->where('is_platform', false)
            ->count();
    }
}
