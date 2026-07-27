<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Application;

use App\Modules\Counterparty\Contracts\CounterpartyRelations;
use App\Modules\Counterparty\Contracts\RelationSnapshot;
use App\Modules\Counterparty\Domain\MovementKind;
use App\Modules\Counterparty\Infrastructure\CounterpartyRelation;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owns the bilateral balance (docs/03-domain/10-counterparty.md §10.9).
 *
 * The one hard requirement here is that two settlements completing at the same
 * instant for the same pair cannot lose each other's update. A read-modify-write
 * (`SELECT balance` → add in PHP → `UPDATE`) does exactly that under concurrency,
 * and a pessimistic lock would serialise every settlement in the market behind
 * the busiest pair. So the write is a single accumulating statement —
 * `INSERT ... ON DUPLICATE KEY UPDATE col = col + VALUES(col)` — which InnoDB
 * applies under a row lock it takes and releases itself. The row need not exist
 * beforehand, which also removes the "who creates the relation" race.
 */
final class RelationService implements CounterpartyRelations
{
    /**
     * Both directions are written in one transaction, and the two statements are
     * ordered by ascending organisation id so that two concurrent trades on the
     * same pair grab the two rows in the same order and cannot deadlock
     * (AGENT_BRIEF rule 4).
     */
    public function applyTrade(
        int $organizationId,
        int $counterpartyOrgId,
        int $goldDeltaMg,
        int $rialDelta,
        ?string $reference = null,
        MovementKind $kind = MovementKind::TRADE,
        ?DateTimeInterface $occurredAt = null,
        ?string $description = null,
    ): void {
        $this->assertDistinctPair($organizationId, $counterpartyOrgId);

        $at = $occurredAt !== null ? Carbon::parse($occurredAt) : Carbon::now();

        $forward = [$organizationId, $counterpartyOrgId, $goldDeltaMg, $rialDelta];
        $mirror = [$counterpartyOrgId, $organizationId, -$goldDeltaMg, -$rialDelta];

        $ordered = $organizationId < $counterpartyOrgId
            ? [$forward, $mirror]
            : [$mirror, $forward];

        DB::transaction(function () use ($ordered, $kind, $reference, $description, $at): void {
            foreach ($ordered as [$left, $right, $gold, $rial]) {
                $this->accumulate($left, $right, $gold, $rial, $kind, $at);
                $this->recordMovement($left, $right, $gold, $rial, $kind, $reference, $description, $at);
            }
        });
    }

    /**
     * The accumulating upsert. Every mutable column is expressed as
     * `column = column + VALUES(column)` so the statement is commutative: the
     * order in which concurrent transactions apply is irrelevant to the result.
     */
    private function accumulate(
        int $organizationId,
        int $counterpartyOrgId,
        int $goldDeltaMg,
        int $rialDelta,
        MovementKind $kind,
        Carbon $at,
    ): void {
        $tradeIncrement = $kind->countsAsTrade() ? 1 : 0;
        $volume = $kind->countsAsTrade() ? abs($goldDeltaMg) : 0;
        // A manual adjustment must not pretend a trade happened, so it carries
        // no trade timestamp; the COALESCE/LEAST dance below keeps the stored
        // value in that case.
        $tradeAt = $kind->countsAsTrade() ? $at->toDateTimeString() : null;
        $now = Carbon::now()->toDateTimeString();

        DB::statement(
            'INSERT INTO counterparty_relations
                (organization_id, counterparty_org_id, gold_balance_mg, rial_balance,
                 total_trade_count, total_volume_mg, first_trade_at, last_trade_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                gold_balance_mg   = gold_balance_mg + VALUES(gold_balance_mg),
                rial_balance      = rial_balance + VALUES(rial_balance),
                total_trade_count = total_trade_count + VALUES(total_trade_count),
                total_volume_mg   = total_volume_mg + VALUES(total_volume_mg),
                first_trade_at    = COALESCE(LEAST(first_trade_at, VALUES(first_trade_at)), first_trade_at, VALUES(first_trade_at)),
                last_trade_at     = COALESCE(GREATEST(last_trade_at, VALUES(last_trade_at)), last_trade_at, VALUES(last_trade_at)),
                updated_at        = ?',
            [
                $organizationId,
                $counterpartyOrgId,
                $goldDeltaMg,
                $rialDelta,
                $tradeIncrement,
                $volume,
                $tradeAt,
                $tradeAt,
                $now,
                $now,
            ],
        );
    }

    private function recordMovement(
        int $organizationId,
        int $counterpartyOrgId,
        int $goldDeltaMg,
        int $rialDelta,
        MovementKind $kind,
        ?string $reference,
        ?string $description,
        Carbon $at,
    ): void {
        DB::table('counterparty_movements')->insert([
            'organization_id' => $organizationId,
            'counterparty_org_id' => $counterpartyOrgId,
            'gold_delta_mg' => $goldDeltaMg,
            'rial_delta' => $rialDelta,
            'kind' => $kind->value,
            'reference' => $reference,
            'description' => $description,
            'occurred_at' => $at->toDateTimeString(),
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    /**
     * Materialise the pair without moving any money — used before setting a
     * limit or a flag on a counterparty we have never traded with.
     */
    public function ensurePair(int $organizationId, int $counterpartyOrgId): void
    {
        $this->assertDistinctPair($organizationId, $counterpartyOrgId);

        $pairs = $organizationId < $counterpartyOrgId
            ? [[$organizationId, $counterpartyOrgId], [$counterpartyOrgId, $organizationId]]
            : [[$counterpartyOrgId, $organizationId], [$organizationId, $counterpartyOrgId]];

        foreach ($pairs as [$left, $right]) {
            DB::statement(
                'INSERT INTO counterparty_relations (organization_id, counterparty_org_id, updated_at)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE organization_id = organization_id',
                [$left, $right, Carbon::now()->toDateTimeString()],
            );
        }
    }

    public function relation(int $organizationId, int $counterpartyOrgId): ?CounterpartyRelation
    {
        return CounterpartyRelation::query()
            ->where('organization_id', $organizationId)
            ->where('counterparty_org_id', $counterpartyOrgId)
            ->first();
    }

    public function snapshot(int $organizationId, int $counterpartyOrgId): ?RelationSnapshot
    {
        return $this->relation($organizationId, $counterpartyOrgId)?->toSnapshot();
    }

    /**
     * @return list<RelationSnapshot>
     */
    public function listFor(int $organizationId): array
    {
        return CounterpartyRelation::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('is_trusted')
            ->orderByDesc('total_volume_mg')
            ->get()
            ->map(static fn (CounterpartyRelation $r): RelationSnapshot => $r->toSnapshot())
            ->all();
    }

    public function isBlocked(int $organizationId, int $counterpartyOrgId): bool
    {
        return $this->flag($organizationId, $counterpartyOrgId, 'is_blocked');
    }

    public function isTrusted(int $organizationId, int $counterpartyOrgId): bool
    {
        return $this->flag($organizationId, $counterpartyOrgId, 'is_trusted');
    }

    public function autoAcceptsOtc(int $organizationId, int $counterpartyOrgId): bool
    {
        // A blocked counterparty never auto-accepts, whatever the other flag says.
        if ($this->isBlocked($organizationId, $counterpartyOrgId)) {
            return false;
        }

        return $this->flag($organizationId, $counterpartyOrgId, 'auto_accept_otc');
    }

    /** @return list<int> */
    public function blockedCounterparties(int $organizationId): array
    {
        return DB::table('counterparty_relations')
            ->where('organization_id', $organizationId)
            ->where('is_blocked', true)
            ->pluck('counterparty_org_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Flags are one-sided: A blocking B says nothing about how B sees A, and the
     * blocked side is never told (§10.7 — silence is the whole point, a
     * consistent rejection would leak the block).
     */
    public function setFlags(
        int $organizationId,
        int $counterpartyOrgId,
        ?bool $isTrusted = null,
        ?bool $isBlocked = null,
        ?bool $autoAcceptOtc = null,
        ?string $internalNote = null,
    ): RelationSnapshot {
        $this->assertDistinctPair($organizationId, $counterpartyOrgId);
        $this->ensurePair($organizationId, $counterpartyOrgId);

        $changes = array_filter([
            'is_trusted' => $isTrusted,
            'is_blocked' => $isBlocked,
            'auto_accept_otc' => $autoAcceptOtc,
        ], static fn (?bool $v): bool => $v !== null);

        if ($internalNote !== null) {
            $changes['internal_note'] = $internalNote;
        }

        // Trusting and blocking the same counterparty is a contradiction that
        // would make the RFQ ordering and the matching filter disagree.
        $relation = $this->relation($organizationId, $counterpartyOrgId);
        $effectiveTrusted = $changes['is_trusted'] ?? $relation?->is_trusted ?? false;
        $effectiveBlocked = $changes['is_blocked'] ?? $relation?->is_blocked ?? false;

        if ($effectiveTrusted && $effectiveBlocked) {
            throw new OperationNotPermittedException(
                'A counterparty cannot be trusted and blocked at the same time.'
            );
        }

        if ($effectiveBlocked) {
            // Blocking withdraws auto-accept implicitly; leaving it set would be
            // a footgun the day the block is lifted.
            $changes['auto_accept_otc'] = false;
        }

        if ($changes !== []) {
            $changes['updated_at'] = Carbon::now()->toDateTimeString();

            DB::table('counterparty_relations')
                ->where('organization_id', $organizationId)
                ->where('counterparty_org_id', $counterpartyOrgId)
                ->update($changes);
        }

        return $this->snapshot($organizationId, $counterpartyOrgId)
            ?? throw new OperationNotPermittedException('Relation disappeared while updating flags.');
    }

    /** Relationship quality counters, incremented from Settlement/Dispute events. */
    public function recordOverdue(int $organizationId, int $counterpartyOrgId): void
    {
        $this->incrementCounter($organizationId, $counterpartyOrgId, 'overdue_count');
    }

    public function recordDispute(int $organizationId, int $counterpartyOrgId): void
    {
        $this->incrementCounter($organizationId, $counterpartyOrgId, 'dispute_count');
    }

    private function incrementCounter(int $organizationId, int $counterpartyOrgId, string $column): void
    {
        $this->assertDistinctPair($organizationId, $counterpartyOrgId);

        DB::statement(
            "INSERT INTO counterparty_relations (organization_id, counterparty_org_id, {$column}, updated_at)
             VALUES (?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE {$column} = {$column} + 1, updated_at = ?",
            [
                $organizationId,
                $counterpartyOrgId,
                Carbon::now()->toDateTimeString(),
                Carbon::now()->toDateTimeString(),
            ],
        );
    }

    private function flag(int $organizationId, int $counterpartyOrgId, string $column): bool
    {
        $value = DB::table('counterparty_relations')
            ->where('organization_id', $organizationId)
            ->where('counterparty_org_id', $counterpartyOrgId)
            ->value($column);

        return (bool) $value;
    }

    private function assertDistinctPair(int $organizationId, int $counterpartyOrgId): void
    {
        if ($organizationId === $counterpartyOrgId) {
            throw new OperationNotPermittedException(
                'An organisation cannot hold a counterparty relation with itself.'
            );
        }

        if ($organizationId <= 0 || $counterpartyOrgId <= 0) {
            throw new OperationNotPermittedException('Organisation ids must be positive.');
        }
    }
}
