<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Console;

use App\Modules\Counterparty\Application\ReconciliationService;
use App\Modules\Counterparty\Contracts\RelationAsymmetry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan counterparty:reconcile` — the nightly invariant check of §10.10.
 *
 * Exits non-zero when anything is wrong, so a scheduler or CI job fails loudly
 * instead of leaving a broken invariant in a log nobody reads. `--fix` is opt-in
 * and rebuilds from the movement log; without it the command only reports.
 */
final class ReconcileRelationsCommand extends Command
{
    protected $signature = 'counterparty:reconcile
                            {--fix : Rebuild drifted balances from the movement log and materialise missing mirrors}
                            {--json : Emit findings as JSON for a monitoring pipeline}';

    protected $description = 'Verify the counterparty relation symmetry invariant and balance/movement agreement';

    public function handle(ReconciliationService $reconciliation): int
    {
        $asymmetries = $reconciliation->asymmetries();
        $orphans = $reconciliation->orphans();
        $drift = $reconciliation->drift();

        $findings = array_merge($asymmetries, $orphans, $drift);

        if ($this->option('json')) {
            $this->line((string) json_encode(
                array_map(static fn (RelationAsymmetry $f): array => $f->toArray(), $findings),
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
            ));
        } else {
            $this->report('Symmetry violations', $asymmetries);
            $this->report('Orphan relations (no mirror row)', $orphans);
            $this->report('Balance drift vs movement log', $drift);
        }

        // An asymmetry means money is recorded differently on the two sides of a
        // real obligation. That is a critical-severity fact, not a warning.
        foreach ($asymmetries as $finding) {
            Log::critical('Counterparty relation asymmetry', $finding->toArray());
        }

        foreach (array_merge($orphans, $drift) as $finding) {
            Log::warning('Counterparty relation drift detected', $finding->toArray());
        }

        if ($this->option('fix')) {
            foreach ($orphans as $orphan) {
                $reconciliation->materialiseMirror($orphan->organizationId, $orphan->counterpartyOrgId);
            }

            foreach ($drift as $finding) {
                $reconciliation->rebuildFromMovements(
                    $finding->organizationId,
                    $finding->counterpartyOrgId,
                );
            }

            $this->info(sprintf(
                'Repaired %d orphan(s) and %d drifted relation(s) from the movement log.',
                count($orphans),
                count($drift),
            ));

            // Asymmetries are never auto-repaired: choosing which of two
            // disagreeing books is right is a business decision.
            if ($asymmetries !== []) {
                $this->warn('Symmetry violations were NOT repaired — they need a human decision.');
            }
        }

        if ($findings === []) {
            $this->info('Counterparty relations reconciled: symmetry and movement totals agree.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    /** @param  list<RelationAsymmetry>  $findings */
    private function report(string $heading, array $findings): void
    {
        if ($findings === []) {
            $this->line(sprintf('<info>%s: none</info>', $heading));

            return;
        }

        $this->line(sprintf('<error>%s: %d</error>', $heading, count($findings)));

        $this->table(
            ['org', 'counterparty', 'gold_mg', 'mirror_gold_mg', 'gold_gap_mg', 'rial', 'mirror_rial', 'rial_gap'],
            array_map(static fn (RelationAsymmetry $f): array => [
                $f->organizationId,
                $f->counterpartyOrgId,
                $f->goldMg,
                $f->mirrorGoldMg ?? '—',
                $f->goldGapMg(),
                $f->rial,
                $f->mirrorRial ?? '—',
                $f->rialGap(),
            ], $findings),
        );
    }
}
