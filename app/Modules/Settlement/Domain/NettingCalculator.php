<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Domain;

use App\Modules\Settlement\Domain\Exceptions\NettingImbalanceException;
use App\Modules\Shared\Support\IntMath;

/**
 * F12 and F13 — docs/11-appendix/01-formulas.md §1.6.
 *
 * Pure arithmetic over a list of Obligations: no database, no ledger, no
 * clock. That is deliberate — netting is the one part of settlement whose
 * correctness is a property of the numbers alone, so it can be exhaustively
 * tested without a fixture in sight, and worked example 3 becomes a unit test.
 *
 * Every sum goes through IntMath so an overflow surfaces as an exception
 * instead of a silently wrapped position.
 */
final class NettingCalculator
{
    /**
     * F13 — multilateral net positions, keyed and ordered by organisation id.
     *
     * @param  list<Obligation>  $obligations
     * @return list<NetPosition> ascending organisation id
     */
    public function netPositions(array $obligations): array
    {
        /** @var array<int, array{in: int, out: int, n: int}> $acc */
        $acc = [];

        foreach ($obligations as $obligation) {
            $acc[$obligation->fromOrganizationId] ??= ['in' => 0, 'out' => 0, 'n' => 0];
            $acc[$obligation->toOrganizationId] ??= ['in' => 0, 'out' => 0, 'n' => 0];

            $from = $obligation->fromOrganizationId;
            $to = $obligation->toOrganizationId;

            $acc[$from]['out'] = IntMath::add($acc[$from]['out'], $obligation->amount);
            $acc[$from]['n']++;
            $acc[$to]['in'] = IntMath::add($acc[$to]['in'], $obligation->amount);
            $acc[$to]['n']++;
        }

        ksort($acc);

        $positions = [];
        foreach ($acc as $organizationId => $row) {
            $positions[] = NetPosition::of($organizationId, $row['in'], $row['out'], $row['n']);
        }

        return $positions;
    }

    /**
     * F12 — one net per unordered pair, ordered by (low, high) organisation id.
     *
     * @param  list<Obligation>  $obligations
     * @return list<BilateralNet>
     */
    public function bilateralNets(array $obligations): array
    {
        /** @var array<string, array{low: int, high: int, fwd: int, rev: int, n: int}> $acc */
        $acc = [];

        foreach ($obligations as $obligation) {
            $key = $obligation->pairKey();
            [$low, $high] = array_map('intval', explode(':', $key));

            $acc[$key] ??= ['low' => $low, 'high' => $high, 'fwd' => 0, 'rev' => 0, 'n' => 0];

            if ($obligation->isForward()) {
                $acc[$key]['fwd'] = IntMath::add($acc[$key]['fwd'], $obligation->amount);
            } else {
                $acc[$key]['rev'] = IntMath::add($acc[$key]['rev'], $obligation->amount);
            }

            $acc[$key]['n']++;
        }

        uasort($acc, static fn (array $a, array $b): int => [$a['low'], $a['high']] <=> [$b['low'], $b['high']]);

        $nets = [];
        foreach ($acc as $row) {
            $nets[] = new BilateralNet(
                lowOrganizationId: $row['low'],
                highOrganizationId: $row['high'],
                net: IntMath::sub($row['fwd'], $row['rev']),
                forwardGross: $row['fwd'],
                reverseGross: $row['rev'],
                obligationCount: $row['n'],
            );
        }

        return $nets;
    }

    /**
     * Invariant N1: Σ net_position over every participant is exactly zero.
     *
     * @param  list<NetPosition>  $positions
     *
     * @throws NettingImbalanceException
     */
    public function assertBalanced(array $positions, string $assetType): void
    {
        $sum = IntMath::sum(array_map(static fn (NetPosition $p): int => $p->net, $positions));

        if ($sum !== 0) {
            throw new NettingImbalanceException($sum, $assetType);
        }
    }

    /** Σ of every obligation in the run — the batch's gross_volume. */
    public function grossVolume(array $obligations): int
    {
        return IntMath::sum(array_map(static fn (Obligation $o): int => $o->amount, $obligations));
    }

    /**
     * Volume that actually moves after multilateral netting: the sum of the
     * creditors' positions, which by N1 equals the sum of the debtors'.
     *
     * @param  list<NetPosition>  $positions
     */
    public function netVolume(array $positions): int
    {
        return IntMath::sum(array_map(
            static fn (NetPosition $p): int => $p->isCreditor() ? $p->net : 0,
            $positions,
        ));
    }

    /**
     * Volume that moves after bilateral netting.
     *
     * @param  list<BilateralNet>  $nets
     */
    public function bilateralVolume(array $nets): int
    {
        return IntMath::sum(array_map(static fn (BilateralNet $n): int => $n->amount(), $nets));
    }

    /**
     * Transfers left after netting. Participants (or pairs) that net to zero
     * need no transfer at all, so they do not count — this is the number
     * compared against gross_transfer_count for invariant N3.
     *
     * @param  list<NetPosition>|list<BilateralNet>  $rows
     */
    public function transferCount(array $rows): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $moves = $row instanceof NetPosition ? ! $row->isFlat() : ! $row->isSettledOut();

            if ($moves) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Invariant N4 — the most important one. What each member's balance change
     * would have been under gross settlement, computed independently of the
     * netting path so a test can compare the two.
     *
     * @param  list<Obligation>  $obligations
     * @return array<int, int> organisation id => signed change
     */
    public function grossOutcome(array $obligations): array
    {
        $outcome = [];

        foreach ($obligations as $obligation) {
            $outcome[$obligation->fromOrganizationId] ??= 0;
            $outcome[$obligation->toOrganizationId] ??= 0;

            $outcome[$obligation->fromOrganizationId] = IntMath::sub(
                $outcome[$obligation->fromOrganizationId],
                $obligation->amount,
            );
            $outcome[$obligation->toOrganizationId] = IntMath::add(
                $outcome[$obligation->toOrganizationId],
                $obligation->amount,
            );
        }

        ksort($outcome);

        return $outcome;
    }
}
