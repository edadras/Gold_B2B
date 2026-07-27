<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Tables;

use App\Modules\Admin\Contracts\SettlementAdminPort;
use App\Modules\Admin\Contracts\SettlementEventRow;
use App\Modules\Admin\Contracts\SettlementRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide settlement slices for the monitor screen.
 *
 * Read-only. Nothing in the admin panel moves a settlement's state machine —
 * that belongs to Settlement, and reaching around it from here is how a
 * settlement ends up in a status its own transitions cannot produce.
 */
final class TableSettlementAdminAdapter implements SettlementAdminPort
{
    /** Statuses that are still someone's obligation. */
    public const OPEN_STATUSES = [
        'CREATED', 'ASSETS_LOCKED', 'PAYMENT_PENDING', 'PAYMENT_DECLARED',
        'PAYMENT_CONFIRMED', 'GOLD_TRANSFERRING', 'NETTING_QUEUE',
    ];

    /** @return list<SettlementRow> */
    public function byStatus(array $statuses, int $limit = 100): array
    {
        if (! Schema::hasTable('settlements')) {
            return [];
        }

        $query = DB::table('settlements as s');

        if (Schema::hasTable('organizations')) {
            $query->leftJoin('organizations as d', 'd.id', '=', 's.gold_deliverer_org_id')
                ->leftJoin('organizations as r', 'r.id', '=', 's.gold_receiver_org_id')
                ->addSelect(['d.display_name as deliverer_name', 'r.display_name as receiver_name']);
        }

        if ($statuses !== []) {
            $query->whereIn('s.status', $statuses);
        }

        $rows = $query
            ->addSelect('s.*')
            // Most overdue first — the monitor exists to surface the ones about
            // to default, not the ones that were created most recently.
            ->orderByRaw('s.overdue_since IS NULL, s.overdue_since ASC')
            ->orderBy('s.deadline_at')
            ->limit($limit)
            ->get();

        return array_map(static fn (object $row): SettlementRow => new SettlementRow(
            id: (int) $row->id,
            settlementCode: (string) $row->settlement_code,
            status: (string) $row->status,
            settlementType: (string) $row->settlement_type,
            tradeId: $row->trade_id === null ? null : (int) $row->trade_id,
            goldDelivererOrgId: (int) $row->gold_deliverer_org_id,
            goldReceiverOrgId: (int) $row->gold_receiver_org_id,
            goldDelivererName: isset($row->deliverer_name) && $row->deliverer_name !== null
                ? (string) $row->deliverer_name
                : null,
            goldReceiverName: isset($row->receiver_name) && $row->receiver_name !== null
                ? (string) $row->receiver_name
                : null,
            fineWeightMg: (int) $row->fine_weight_mg,
            cashAmountRial: (int) $row->cash_amount_rial,
            deadlineAt: $row->deadline_at === null ? null : (string) $row->deadline_at,
            overdueSince: $row->overdue_since === null ? null : (string) $row->overdue_since,
            penaltyRial: (int) ($row->penalty_rial ?? 0),
            escalationLevel: (int) ($row->escalation_level ?? 0),
            disputeId: $row->dispute_id === null ? null : (int) $row->dispute_id,
        ), $rows->all());
    }

    /** @return array<string, int> */
    public function statusCounts(): array
    {
        if (! Schema::hasTable('settlements')) {
            return [];
        }

        $rows = DB::table('settlements')
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->status] = (int) $row->total;
        }

        return $out;
    }

    public function find(int $settlementId): ?SettlementRow
    {
        $rows = $this->byStatusForId($settlementId);

        return $rows[0] ?? null;
    }

    /** @return list<SettlementRow> */
    private function byStatusForId(int $settlementId): array
    {
        if (! Schema::hasTable('settlements')) {
            return [];
        }

        $query = DB::table('settlements as s')->where('s.id', $settlementId);

        if (Schema::hasTable('organizations')) {
            $query->leftJoin('organizations as d', 'd.id', '=', 's.gold_deliverer_org_id')
                ->leftJoin('organizations as r', 'r.id', '=', 's.gold_receiver_org_id')
                ->addSelect(['d.display_name as deliverer_name', 'r.display_name as receiver_name']);
        }

        $row = $query->addSelect('s.*')->first();

        if ($row === null) {
            return [];
        }

        return [new SettlementRow(
            id: (int) $row->id,
            settlementCode: (string) $row->settlement_code,
            status: (string) $row->status,
            settlementType: (string) $row->settlement_type,
            tradeId: $row->trade_id === null ? null : (int) $row->trade_id,
            goldDelivererOrgId: (int) $row->gold_deliverer_org_id,
            goldReceiverOrgId: (int) $row->gold_receiver_org_id,
            goldDelivererName: isset($row->deliverer_name) && $row->deliverer_name !== null
                ? (string) $row->deliverer_name
                : null,
            goldReceiverName: isset($row->receiver_name) && $row->receiver_name !== null
                ? (string) $row->receiver_name
                : null,
            fineWeightMg: (int) $row->fine_weight_mg,
            cashAmountRial: (int) $row->cash_amount_rial,
            deadlineAt: $row->deadline_at === null ? null : (string) $row->deadline_at,
            overdueSince: $row->overdue_since === null ? null : (string) $row->overdue_since,
            penaltyRial: (int) ($row->penalty_rial ?? 0),
            escalationLevel: (int) ($row->escalation_level ?? 0),
            disputeId: $row->dispute_id === null ? null : (int) $row->dispute_id,
        )];
    }

    /** @return list<SettlementEventRow> */
    public function timeline(int $settlementId): array
    {
        if (! Schema::hasTable('settlement_events')) {
            return [];
        }

        $rows = DB::table('settlement_events')
            ->where('settlement_id', $settlementId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        return array_map(static fn (object $row): SettlementEventRow => new SettlementEventRow(
            id: (int) $row->id,
            fromStatus: $row->from_status === null ? null : (string) $row->from_status,
            toStatus: (string) $row->to_status,
            actorType: (string) $row->actor_type,
            actorUserId: $row->actor_user_id === null ? null : (int) $row->actor_user_id,
            reason: $row->reason === null ? null : (string) $row->reason,
            transactionGroup: $row->transaction_group === null ? null : (string) $row->transaction_group,
            occurredAt: (string) $row->occurred_at,
        ), $rows->all());
    }

    /** @return array{on_time: int, late: int, defaulted: int} */
    public function healthBreakdown(): array
    {
        $counts = $this->statusCounts();

        $completed = ($counts['COMPLETED'] ?? 0) + ($counts['SETTLED'] ?? 0);
        $late = $counts['OVERDUE'] ?? 0;
        $defaulted = $counts['DEFAULTED'] ?? 0;

        return [
            'on_time' => max(0, $completed - $late),
            'late' => $late,
            'defaulted' => $defaulted,
        ];
    }
}
