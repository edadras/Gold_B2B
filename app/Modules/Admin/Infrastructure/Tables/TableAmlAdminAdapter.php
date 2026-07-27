<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Tables;

use App\Modules\Admin\Contracts\AmlAdminPort;
use App\Modules\Admin\Contracts\AmlFlagRow;
use App\Modules\Admin\Contracts\AmlInvestigationContext;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AML flag queue and investigation context, read off Risk's tables.
 *
 * Risk publishes `AmlEvaluatorInterface` (raise flags) but nothing that reads
 * the queue back, so this adapter fills the gap.
 */
final class TableAmlAdminAdapter implements AmlAdminPort
{
    /** Flags that still need an analyst. */
    public const OPEN_STATUSES = ['OPEN', 'UNDER_REVIEW', 'ENHANCED_REVIEW', 'ESCALATED'];

    public const DECISION_STATUSES = [
        'UNDER_REVIEW', 'ENHANCED_REVIEW', 'CLEARED', 'FALSE_POSITIVE', 'ESCALATED', 'ACTION_TAKEN',
    ];

    /** @return list<AmlFlagRow> */
    public function flags(array $statuses = [], array $severities = [], int $limit = 100): array
    {
        if (! Schema::hasTable('aml_flags')) {
            return [];
        }

        $query = DB::table('aml_flags as f')->select('f.*');

        if (Schema::hasTable('organizations')) {
            $query->leftJoin('organizations as o', 'o.id', '=', 'f.organization_id')
                ->addSelect('o.display_name as organization_name');
        }

        if (Schema::hasTable('aml_rules')) {
            $query->leftJoin('aml_rules as r', 'r.code', '=', 'f.rule_code')
                ->addSelect('r.name as rule_name');
        }

        if ($statuses !== []) {
            $query->whereIn('f.status', $statuses);
        }

        if ($severities !== []) {
            $query->whereIn('f.severity', $severities);
        }

        // CRITICAL first, then oldest: severity outranks age, but within a
        // severity an old flag is worse than a new one.
        $rows = $query
            ->orderByRaw("FIELD(f.severity, 'CRITICAL', 'HIGH', 'MEDIUM', 'LOW')")
            ->orderBy('f.raised_at')
            ->limit($limit)
            ->get();

        return array_map(fn (object $row): AmlFlagRow => $this->toRow($row), $rows->all());
    }

    public function countOpen(): int
    {
        if (! Schema::hasTable('aml_flags')) {
            return 0;
        }

        return (int) DB::table('aml_flags')->whereIn('status', self::OPEN_STATUSES)->count();
    }

    public function countCritical(): int
    {
        if (! Schema::hasTable('aml_flags')) {
            return 0;
        }

        return (int) DB::table('aml_flags')
            ->where('severity', 'CRITICAL')
            ->whereIn('status', self::OPEN_STATUSES)
            ->count();
    }

    public function find(int $flagId): ?AmlFlagRow
    {
        if (! Schema::hasTable('aml_flags')) {
            return null;
        }

        $query = DB::table('aml_flags as f')->select('f.*')->where('f.id', $flagId);

        if (Schema::hasTable('organizations')) {
            $query->leftJoin('organizations as o', 'o.id', '=', 'f.organization_id')
                ->addSelect('o.display_name as organization_name');
        }

        if (Schema::hasTable('aml_rules')) {
            $query->leftJoin('aml_rules as r', 'r.code', '=', 'f.rule_code')
                ->addSelect('r.name as rule_name');
        }

        $row = $query->first();

        return $row === null ? null : $this->toRow($row);
    }

    public function investigationContext(int $flagId): ?AmlInvestigationContext
    {
        $flag = $this->find($flagId);

        if ($flag === null) {
            return null;
        }

        $organization = Schema::hasTable('organizations')
            ? DB::table('organizations')->where('id', $flag->organizationId)->first()
            : null;

        return new AmlInvestigationContext(
            flag: $flag,
            organizationStatus: $organization === null ? null : (string) $organization->status,
            organizationRiskLevel: $organization === null ? null : (string) $organization->risk_level,
            complianceState: $organization === null ? null : (string) $organization->compliance_state,
            otherFlags: $this->otherFlags($flag),
            recentTrades: $this->recentTrades($flag->organizationId),
            balances: $this->balances($flag->organizationId),
            openDisputeCount: $this->openDisputeCount($flag->organizationId),
            overdueSettlementCount: $this->overdueSettlementCount($flag->organizationId),
        );
    }

    public function recordDecision(
        int $flagId,
        int $analystUserId,
        string $status,
        string $notes,
        ?string $actionTaken,
    ): void {
        if (! Schema::hasTable('aml_flags')) {
            throw new OperationNotPermittedException('جدول پرچم‌های AML در دسترس نیست.');
        }

        if (trim($notes) === '') {
            throw new OperationNotPermittedException('تغییر وضعیت پرچم بدون یادداشت مجاز نیست.');
        }

        if (! in_array($status, self::DECISION_STATUSES, true)) {
            throw new OperationNotPermittedException("وضعیت نامعتبر برای پرچم AML: {$status}");
        }

        $updated = DB::table('aml_flags')->where('id', $flagId)->update([
            'status' => $status,
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $analystUserId,
            'resolution_notes' => $notes,
            'action_taken' => $actionTaken,
            'updated_at' => now(),
        ]);

        if ($updated === 0) {
            throw new OperationNotPermittedException("پرچم AML شماره {$flagId} یافت نشد.");
        }
    }

    private function toRow(object $row): AmlFlagRow
    {
        $context = [];

        if (isset($row->context) && $row->context !== null) {
            $decoded = json_decode((string) $row->context, true);
            $context = is_array($decoded) ? $decoded : [];
        }

        return new AmlFlagRow(
            id: (int) $row->id,
            ruleCode: (string) $row->rule_code,
            ruleName: isset($row->rule_name) && $row->rule_name !== null ? (string) $row->rule_name : null,
            organizationId: (int) $row->organization_id,
            organizationName: isset($row->organization_name) && $row->organization_name !== null
                ? (string) $row->organization_name
                : null,
            userId: $row->user_id === null ? null : (int) $row->user_id,
            severity: (string) $row->severity,
            status: (string) $row->status,
            summary: (string) $row->summary,
            subjectType: $row->subject_type === null ? null : (string) $row->subject_type,
            subjectId: $row->subject_id === null ? null : (int) $row->subject_id,
            raisedAt: (string) $row->raised_at,
            assignedToUserId: $row->assigned_to_user_id === null ? null : (int) $row->assigned_to_user_id,
            resolutionNotes: $row->resolution_notes === null ? null : (string) $row->resolution_notes,
            context: $context,
        );
    }

    /** @return list<AmlFlagRow> */
    private function otherFlags(AmlFlagRow $flag): array
    {
        $rows = DB::table('aml_flags as f')
            ->where('f.organization_id', $flag->organizationId)
            ->where('f.id', '!=', $flag->id)
            ->orderByDesc('f.raised_at')
            ->limit(20)
            ->get();

        return array_map(fn (object $row): AmlFlagRow => $this->toRow($row), $rows->all());
    }

    /** @return list<array{id: int, trade_code: ?string, counterparty_id: int, quantity_fine_mg: int, gross_amount_rial: int, executed_at: ?string}> */
    private function recentTrades(int $organizationId): array
    {
        if (! Schema::hasTable('trades')) {
            return [];
        }

        $rows = DB::table('trades')
            ->where(function ($q) use ($organizationId): void {
                $q->where('buyer_organization_id', $organizationId)
                    ->orWhere('seller_organization_id', $organizationId);
            })
            ->orderByDesc('executed_at')
            ->limit(20)
            ->get();

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'trade_code' => $row->trade_code === null ? null : (string) $row->trade_code,
            'counterparty_id' => (int) $row->buyer_organization_id === $organizationId
                ? (int) $row->seller_organization_id
                : (int) $row->buyer_organization_id,
            'quantity_fine_mg' => (int) $row->quantity_fine_mg,
            'gross_amount_rial' => (int) $row->gross_amount_rial,
            'executed_at' => $row->executed_at === null ? null : (string) $row->executed_at,
        ], $rows->all());
    }

    /** @return array<string, int> */
    private function balances(int $organizationId): array
    {
        if (! Schema::hasTable('ledger_accounts') || ! Schema::hasTable('ledger_balances')) {
            return [];
        }

        $rows = DB::table('ledger_accounts as a')
            ->leftJoin('ledger_balances as b', 'b.account_id', '=', 'a.id')
            ->where('a.organization_id', $organizationId)
            ->selectRaw('a.asset_type, COALESCE(SUM(b.balance), 0) AS total')
            ->groupBy('a.asset_type')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->asset_type] = (int) $row->total;
        }

        return $out;
    }

    private function openDisputeCount(int $organizationId): int
    {
        if (! Schema::hasTable('disputes')) {
            return 0;
        }

        return (int) DB::table('disputes')
            ->whereNotIn('status', ['EXECUTED', 'WITHDRAWN'])
            ->where(function ($q) use ($organizationId): void {
                $q->where('claimant_org_id', $organizationId)
                    ->orWhere('respondent_org_id', $organizationId);
            })
            ->count();
    }

    private function overdueSettlementCount(int $organizationId): int
    {
        if (! Schema::hasTable('settlements')) {
            return 0;
        }

        return (int) DB::table('settlements')
            ->whereIn('status', ['OVERDUE', 'DEFAULTED'])
            ->where(function ($q) use ($organizationId): void {
                $q->where('gold_deliverer_org_id', $organizationId)
                    ->orWhere('cash_payer_org_id', $organizationId);
            })
            ->count();
    }
}
