<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Application;

use App\Modules\Counterparty\Contracts\Statement;
use App\Modules\Counterparty\Contracts\StatementLine;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Builds the running statement of docs/03-domain/10-counterparty.md §10.3.
 *
 * Opening balance is the sum of every movement strictly before the period, the
 * body is the movements inside it with a running balance, and the closing
 * balance is opening + body. That figure is then compared against the relation
 * row, which is maintained by a completely different write path (the
 * accumulating upsert in RelationService). The two agreeing is real evidence;
 * deriving the closing balance *from* the relation row would have been
 * circular and would have proved nothing.
 */
final class StatementService
{
    public function __construct(private readonly RelationService $relations) {}

    public function build(
        int $organizationId,
        int $counterpartyOrgId,
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
    ): Statement {
        $fromAt = Carbon::parse($from);
        $toAt = Carbon::parse($to);

        $opening = $this->sumBefore($organizationId, $counterpartyOrgId, $fromAt);

        $runningGold = $opening['gold_mg'];
        $runningRial = $opening['rial'];

        $lines = [];

        foreach ($this->movementsIn($organizationId, $counterpartyOrgId, $fromAt, $toAt) as $row) {
            $runningGold += (int) $row->gold_delta_mg;
            $runningRial += (int) $row->rial_delta;

            $lines[] = new StatementLine(
                movementId: (int) $row->id,
                occurredAt: (string) $row->occurred_at,
                kind: (string) $row->kind,
                reference: $row->reference !== null ? (string) $row->reference : null,
                description: $row->description !== null ? (string) $row->description : null,
                goldDeltaMg: (int) $row->gold_delta_mg,
                rialDelta: (int) $row->rial_delta,
                runningGoldMg: $runningGold,
                runningRial: $runningRial,
            );
        }

        $relation = $this->relations->relation($organizationId, $counterpartyOrgId);
        $storedGold = $relation?->gold_balance_mg ?? 0;
        $storedRial = $relation?->rial_balance ?? 0;

        $after = $this->countAfter($organizationId, $counterpartyOrgId, $toAt);

        return new Statement(
            organizationId: $organizationId,
            counterpartyOrgId: $counterpartyOrgId,
            from: $fromAt->toIso8601String(),
            to: $toAt->toIso8601String(),
            openingGoldMg: $opening['gold_mg'],
            openingRial: $opening['rial'],
            lines: $lines,
            closingGoldMg: $runningGold,
            closingRial: $runningRial,
            storedGoldMg: $storedGold,
            storedRial: $storedRial,
            hasMovementsAfterPeriod: $after > 0,
            isReconciled: $this->isReconciled(
                $organizationId,
                $counterpartyOrgId,
                $runningGold,
                $runningRial,
                $storedGold,
                $storedRial,
                $after > 0,
            ),
            relation: $relation?->toSnapshot(),
        );
    }

    /**
     * When the period runs to the present, the closing figure must equal the
     * stored balance. When the caller asked for a historical window, the honest
     * check is that the *whole* movement history still sums to the stored
     * balance — the closing figure of a past month legitimately differs from
     * today's.
     */
    private function isReconciled(
        int $organizationId,
        int $counterpartyOrgId,
        int $closingGold,
        int $closingRial,
        int $storedGold,
        int $storedRial,
        bool $hasLaterMovements,
    ): bool {
        if (! $hasLaterMovements) {
            return $closingGold === $storedGold && $closingRial === $storedRial;
        }

        $total = $this->sumAll($organizationId, $counterpartyOrgId);

        return $total['gold_mg'] === $storedGold && $total['rial'] === $storedRial;
    }

    /** @return array{gold_mg: int, rial: int} */
    private function sumBefore(int $organizationId, int $counterpartyOrgId, Carbon $before): array
    {
        return $this->sum(
            $this->pairQuery($organizationId, $counterpartyOrgId)
                ->where('occurred_at', '<', $before->toDateTimeString())
        );
    }

    /** @return array{gold_mg: int, rial: int} */
    private function sumAll(int $organizationId, int $counterpartyOrgId): array
    {
        return $this->sum($this->pairQuery($organizationId, $counterpartyOrgId));
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return array{gold_mg: int, rial: int}
     */
    private function sum($query): array
    {
        $row = $query->selectRaw(
            'COALESCE(SUM(gold_delta_mg), 0) AS gold_mg, COALESCE(SUM(rial_delta), 0) AS rial'
        )->first();

        return [
            'gold_mg' => (int) ($row->gold_mg ?? 0),
            'rial' => (int) ($row->rial ?? 0),
        ];
    }

    /** @return list<stdClass> */
    private function movementsIn(
        int $organizationId,
        int $counterpartyOrgId,
        Carbon $from,
        Carbon $to,
    ): array {
        return $this->pairQuery($organizationId, $counterpartyOrgId)
            ->whereBetween('occurred_at', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->all();
    }

    private function countAfter(int $organizationId, int $counterpartyOrgId, Carbon $to): int
    {
        return $this->pairQuery($organizationId, $counterpartyOrgId)
            ->where('occurred_at', '>', $to->toDateTimeString())
            ->count();
    }

    private function pairQuery(int $organizationId, int $counterpartyOrgId): \Illuminate\Database\Query\Builder
    {
        return DB::table('counterparty_movements')
            ->where('organization_id', $organizationId)
            ->where('counterparty_org_id', $counterpartyOrgId);
    }
}
