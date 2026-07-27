<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Application;

use App\Modules\Counterparty\Contracts\RelationAsymmetry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Nightly proof that the bilateral book still holds together
 * (docs/03-domain/10-counterparty.md §10.10).
 *
 * Two independent checks:
 *  1. Symmetry — relation(A,B) must be the exact negation of relation(B,A).
 *     Failure means a write applied to one side only, which is the one thing
 *     the transactional double-upsert in RelationService is supposed to make
 *     impossible; if it ever fires, something bypassed the service.
 *  2. Drift — each relation balance must equal the sum of its own movements.
 *     Failure means the balance was edited outside the movement log.
 *
 * The checks are separate on purpose: a pair can be perfectly symmetric and
 * still wrong on both sides, and symmetry alone would never notice.
 */
final class ReconciliationService
{
    /**
     * The join from §10.10. Each asymmetric pair appears twice (once from each
     * side); only the (A < B) orientation is reported so the operator sees one
     * finding per pair.
     *
     * @return list<RelationAsymmetry>
     */
    public function asymmetries(): array
    {
        $rows = DB::select(
            'SELECT a.organization_id, a.counterparty_org_id,
                    a.gold_balance_mg AS a_gold, b.gold_balance_mg AS b_gold,
                    a.rial_balance    AS a_rial, b.rial_balance    AS b_rial
             FROM counterparty_relations a
             JOIN counterparty_relations b
               ON b.organization_id = a.counterparty_org_id
              AND b.counterparty_org_id = a.organization_id
             WHERE a.organization_id < a.counterparty_org_id
               AND (a.gold_balance_mg != -b.gold_balance_mg
                 OR a.rial_balance    != -b.rial_balance)'
        );

        return array_map(
            static fn (object $row): RelationAsymmetry => new RelationAsymmetry(
                kind: RelationAsymmetry::ASYMMETRIC,
                organizationId: (int) $row->organization_id,
                counterpartyOrgId: (int) $row->counterparty_org_id,
                goldMg: (int) $row->a_gold,
                mirrorGoldMg: (int) $row->b_gold,
                rial: (int) $row->a_rial,
                mirrorRial: (int) $row->b_rial,
            ),
            $rows,
        );
    }

    /**
     * Relations whose mirror row does not exist at all.
     *
     * @return list<RelationAsymmetry>
     */
    public function orphans(): array
    {
        $rows = DB::select(
            'SELECT a.organization_id, a.counterparty_org_id,
                    a.gold_balance_mg AS a_gold, a.rial_balance AS a_rial
             FROM counterparty_relations a
             LEFT JOIN counterparty_relations b
               ON b.organization_id = a.counterparty_org_id
              AND b.counterparty_org_id = a.organization_id
             WHERE b.id IS NULL'
        );

        return array_map(
            static fn (object $row): RelationAsymmetry => new RelationAsymmetry(
                kind: RelationAsymmetry::ORPHAN,
                organizationId: (int) $row->organization_id,
                counterpartyOrgId: (int) $row->counterparty_org_id,
                goldMg: (int) $row->a_gold,
                mirrorGoldMg: null,
                rial: (int) $row->a_rial,
                mirrorRial: null,
            ),
            $rows,
        );
    }

    /**
     * Relations whose stored balance disagrees with the sum of their movements.
     *
     * @return list<RelationAsymmetry>
     */
    public function drift(): array
    {
        $rows = DB::select(
            'SELECT r.organization_id, r.counterparty_org_id,
                    r.gold_balance_mg AS stored_gold, r.rial_balance AS stored_rial,
                    COALESCE(m.gold_mg, 0) AS computed_gold,
                    COALESCE(m.rial, 0)    AS computed_rial
             FROM counterparty_relations r
             LEFT JOIN (
                 SELECT organization_id, counterparty_org_id,
                        SUM(gold_delta_mg) AS gold_mg, SUM(rial_delta) AS rial
                 FROM counterparty_movements
                 GROUP BY organization_id, counterparty_org_id
             ) m ON m.organization_id = r.organization_id
                AND m.counterparty_org_id = r.counterparty_org_id
             WHERE r.gold_balance_mg != COALESCE(m.gold_mg, 0)
                OR r.rial_balance    != COALESCE(m.rial, 0)'
        );

        return array_map(
            static fn (object $row): RelationAsymmetry => new RelationAsymmetry(
                kind: RelationAsymmetry::DRIFT,
                organizationId: (int) $row->organization_id,
                counterpartyOrgId: (int) $row->counterparty_org_id,
                // Negated so goldGapMg() (which adds the two) reports the
                // stored-minus-computed difference for a drift finding too.
                goldMg: (int) $row->stored_gold,
                mirrorGoldMg: -(int) $row->computed_gold,
                rial: (int) $row->stored_rial,
                mirrorRial: -(int) $row->computed_rial,
            ),
            $rows,
        );
    }

    /**
     * Rebuild one relation's balance from its movement log (§10.10 step 2).
     *
     * Only ever called with an operator's explicit consent: the movement log is
     * the more trustworthy of the two, but silently rewriting balances during a
     * routine health check would hide the bug that caused the drift.
     */
    public function rebuildFromMovements(int $organizationId, int $counterpartyOrgId): void
    {
        DB::transaction(function () use ($organizationId, $counterpartyOrgId): void {
            $row = DB::table('counterparty_movements')
                ->where('organization_id', $organizationId)
                ->where('counterparty_org_id', $counterpartyOrgId)
                ->selectRaw('COALESCE(SUM(gold_delta_mg), 0) AS gold_mg, COALESCE(SUM(rial_delta), 0) AS rial')
                ->first();

            DB::table('counterparty_relations')
                ->where('organization_id', $organizationId)
                ->where('counterparty_org_id', $counterpartyOrgId)
                ->update([
                    'gold_balance_mg' => (int) ($row->gold_mg ?? 0),
                    'rial_balance' => (int) ($row->rial ?? 0),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
        });
    }

    /**
     * Create the missing mirror of an orphan relation, so the invariant can be
     * evaluated at all. The new row starts at the exact negation of the one that
     * exists.
     */
    public function materialiseMirror(int $organizationId, int $counterpartyOrgId): void
    {
        $source = DB::table('counterparty_relations')
            ->where('organization_id', $organizationId)
            ->where('counterparty_org_id', $counterpartyOrgId)
            ->first();

        if ($source === null) {
            return;
        }

        DB::statement(
            'INSERT INTO counterparty_relations
                (organization_id, counterparty_org_id, gold_balance_mg, rial_balance, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE organization_id = organization_id',
            [
                $counterpartyOrgId,
                $organizationId,
                -(int) $source->gold_balance_mg,
                -(int) $source->rial_balance,
                Carbon::now()->toDateTimeString(),
            ],
        );
    }

    /**
     * Everything wrong with the bilateral book right now.
     *
     * @return list<RelationAsymmetry>
     */
    public function findings(): array
    {
        return array_merge($this->asymmetries(), $this->orphans(), $this->drift());
    }
}
