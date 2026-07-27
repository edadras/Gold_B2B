<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Tables;

use App\Modules\Admin\Contracts\DisputeAdminPort;
use App\Modules\Admin\Contracts\DisputeInvestigationContext;
use App\Modules\Admin\Contracts\DisputeRow;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dispute queue for the platform mediator.
 *
 * Dispute publishes `DisputeHoldPort` and `TradePartiesProvider` — both inbound
 * ports it consumes, not outbound reads — so the queue is assembled here.
 */
final class TableDisputeAdminAdapter implements DisputeAdminPort
{
    /** Statuses where a platform mediator is the next actor. */
    public const AWAITING_MEDIATION = ['UNDER_MEDIATION', 'AWAITING_EVIDENCE', 'AWAITING_REASSAY'];

    public const OPEN_STATUSES = [
        'OPENED', 'AWAITING_REPLY', 'ACCEPTED_BY_RESPONDENT', 'NEGOTIATION',
        'UNDER_MEDIATION', 'AWAITING_EVIDENCE', 'AWAITING_REASSAY', 'RESOLVED',
    ];

    /** @return list<DisputeRow> */
    public function queue(array $statuses = [], int $limit = 100): array
    {
        if (! Schema::hasTable('disputes')) {
            return [];
        }

        $query = DB::table('disputes as d')->select('d.*');

        if (Schema::hasTable('organizations')) {
            $query->leftJoin('organizations as c', 'c.id', '=', 'd.claimant_org_id')
                ->leftJoin('organizations as r', 'r.id', '=', 'd.respondent_org_id')
                ->addSelect(['c.display_name as claimant_name', 'r.display_name as respondent_name']);
        }

        if ($statuses !== []) {
            $query->whereIn('d.status', $statuses);
        }

        $rows = $query
            ->orderByRaw("FIELD(d.priority, 'URGENT', 'HIGH', 'NORMAL', 'LOW')")
            ->orderBy('d.opened_at')
            ->limit($limit)
            ->get();

        return array_map(fn (object $row): DisputeRow => $this->toRow($row), $rows->all());
    }

    public function countAwaitingMediation(): int
    {
        if (! Schema::hasTable('disputes')) {
            return 0;
        }

        return (int) DB::table('disputes')->whereIn('status', self::AWAITING_MEDIATION)->count();
    }

    public function find(int $disputeId): ?DisputeRow
    {
        if (! Schema::hasTable('disputes')) {
            return null;
        }

        $query = DB::table('disputes as d')->select('d.*')->where('d.id', $disputeId);

        if (Schema::hasTable('organizations')) {
            $query->leftJoin('organizations as c', 'c.id', '=', 'd.claimant_org_id')
                ->leftJoin('organizations as r', 'r.id', '=', 'd.respondent_org_id')
                ->addSelect(['c.display_name as claimant_name', 'r.display_name as respondent_name']);
        }

        $row = $query->first();

        return $row === null ? null : $this->toRow($row);
    }

    public function investigationContext(int $disputeId): ?DisputeInvestigationContext
    {
        $dispute = $this->find($disputeId);

        if ($dispute === null) {
            return null;
        }

        $raw = DB::table('disputes')->where('id', $disputeId)->first();

        return new DisputeInvestigationContext(
            dispute: $dispute,
            timeline: $this->timeline($disputeId),
            evidence: $this->evidence($disputeId),
            trade: $this->trade($dispute->tradeId),
            settlement: $this->settlement($dispute->settlementId),
            holdReleased: (bool) ($raw->hold_released ?? false),
            holdGoldEntryId: isset($raw->hold_gold_entry_id) && $raw->hold_gold_entry_id !== null
                ? (int) $raw->hold_gold_entry_id
                : null,
            holdRialEntryId: isset($raw->hold_rial_entry_id) && $raw->hold_rial_entry_id !== null
                ? (int) $raw->hold_rial_entry_id
                : null,
        );
    }

    public function assignMediator(int $disputeId, int $mediatorUserId, int $actorUserId, string $note): void
    {
        if (! Schema::hasTable('disputes')) {
            throw new OperationNotPermittedException('جدول اختلافات در دسترس نیست.');
        }

        if (trim($note) === '') {
            throw new OperationNotPermittedException('تعیین میانجی بدون یادداشت مجاز نیست.');
        }

        $dispute = DB::table('disputes')->where('id', $disputeId)->first();

        if ($dispute === null) {
            throw new OperationNotPermittedException("اختلاف شماره {$disputeId} یافت نشد.");
        }

        DB::transaction(function () use ($dispute, $disputeId, $mediatorUserId, $actorUserId, $note): void {
            DB::table('disputes')->where('id', $disputeId)->update([
                'mediator_user_id' => $mediatorUserId,
                'status' => 'UNDER_MEDIATION',
                'updated_at' => now(),
            ]);

            if (Schema::hasTable('dispute_timeline')) {
                DB::table('dispute_timeline')->insert([
                    'dispute_id' => $disputeId,
                    // The enum on this table names the platform actor MEDIATOR;
                    // there is no PLATFORM member, and inventing one here would
                    // be silently truncated by MariaDB.
                    'actor_type' => 'MEDIATOR',
                    'actor_user_id' => $actorUserId,
                    'actor_org_id' => null,
                    'action' => 'mediator.assigned',
                    'message' => $note,
                    'from_status' => (string) $dispute->status,
                    'to_status' => 'UNDER_MEDIATION',
                    'occurred_at' => now(),
                ]);
            }
        });
    }

    private function toRow(object $row): DisputeRow
    {
        return new DisputeRow(
            id: (int) $row->id,
            caseNumber: (string) $row->case_number,
            disputeType: (string) $row->dispute_type,
            status: (string) $row->status,
            priority: (string) $row->priority,
            claimantOrgId: (int) $row->claimant_org_id,
            respondentOrgId: (int) $row->respondent_org_id,
            claimantName: isset($row->claimant_name) && $row->claimant_name !== null
                ? (string) $row->claimant_name
                : null,
            respondentName: isset($row->respondent_name) && $row->respondent_name !== null
                ? (string) $row->respondent_name
                : null,
            claimGoldMg: (int) ($row->claim_gold_mg ?? 0),
            claimRial: (int) ($row->claim_rial ?? 0),
            tradeId: $row->trade_id === null ? null : (int) $row->trade_id,
            settlementId: $row->settlement_id === null ? null : (int) $row->settlement_id,
            mediatorUserId: $row->mediator_user_id === null ? null : (int) $row->mediator_user_id,
            replyDeadlineAt: $row->reply_deadline_at === null ? null : (string) $row->reply_deadline_at,
            negotiationDeadlineAt: $row->negotiation_deadline_at === null
                ? null
                : (string) $row->negotiation_deadline_at,
            openedAt: (string) $row->opened_at,
        );
    }

    /** @return list<array{id: int, actor_type: string, actor_user_id: ?int, action: string, message: ?string, from_status: ?string, to_status: ?string, occurred_at: string}> */
    private function timeline(int $disputeId): array
    {
        if (! Schema::hasTable('dispute_timeline')) {
            return [];
        }

        $rows = DB::table('dispute_timeline')
            ->where('dispute_id', $disputeId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'actor_type' => (string) $row->actor_type,
            'actor_user_id' => $row->actor_user_id === null ? null : (int) $row->actor_user_id,
            'action' => (string) $row->action,
            'message' => $row->message === null ? null : (string) $row->message,
            'from_status' => $row->from_status === null ? null : (string) $row->from_status,
            'to_status' => $row->to_status === null ? null : (string) $row->to_status,
            'occurred_at' => (string) $row->occurred_at,
        ], $rows->all());
    }

    /** @return list<array{id: int, evidence_type: ?string, submitted_by_org_id: ?int, created_at: ?string}> */
    private function evidence(int $disputeId): array
    {
        if (! Schema::hasTable('dispute_evidences')) {
            return [];
        }

        $columns = Schema::getColumnListing('dispute_evidences');

        $rows = DB::table('dispute_evidences')
            ->where('dispute_id', $disputeId)
            ->orderBy('id')
            ->get();

        return array_map(static function (object $row) use ($columns): array {
            $orgColumn = in_array('submitted_by_org_id', $columns, true)
                ? 'submitted_by_org_id'
                : (in_array('organization_id', $columns, true) ? 'organization_id' : null);

            return [
                'id' => (int) $row->id,
                'evidence_type' => isset($row->evidence_type) && $row->evidence_type !== null
                    ? (string) $row->evidence_type
                    : null,
                'submitted_by_org_id' => $orgColumn !== null && isset($row->{$orgColumn}) && $row->{$orgColumn} !== null
                    ? (int) $row->{$orgColumn}
                    : null,
                'created_at' => isset($row->submitted_at) && $row->submitted_at !== null
                    ? (string) $row->submitted_at
                    : null,
            ];
        }, $rows->all());
    }

    /** @return array<string, mixed>|null */
    private function trade(?int $tradeId): ?array
    {
        if ($tradeId === null || ! Schema::hasTable('trades')) {
            return null;
        }

        $row = DB::table('trades')->where('id', $tradeId)->first();

        return $row === null ? null : (array) $row;
    }

    /** @return array<string, mixed>|null */
    private function settlement(?int $settlementId): ?array
    {
        if ($settlementId === null || ! Schema::hasTable('settlements')) {
            return null;
        }

        $row = DB::table('settlements')->where('id', $settlementId)->first();

        return $row === null ? null : (array) $row;
    }
}
