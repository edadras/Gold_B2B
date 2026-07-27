<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Tests\Unit;

use App\Modules\Settlement\Domain\BilateralNet;
use App\Modules\Settlement\Domain\Exceptions\NettingImbalanceException;
use App\Modules\Settlement\Domain\NetPosition;
use App\Modules\Settlement\Domain\NettingCalculator;
use App\Modules\Settlement\Domain\Obligation;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * F12 and F13 against the five-member trading day of
 * docs/03-domain/05-settlement.md §5.6 — the section's own motivating example.
 *
 * Ten transfers of gold and ten of cash: twenty operations. Bilaterally that
 * becomes five transfers, a 50 % reduction. Multilaterally each member has a
 * single flow, and the five positions sum to zero.
 *
 * Pure arithmetic, so no database and no container: if these numbers are wrong
 * everything built on them is wrong, and that should be visible in
 * milliseconds.
 */
final class NettingCalculatorTest extends TestCase
{
    private const A = 1;

    private const B = 2;

    private const C = 3;

    private const D = 4;

    private const E = 5;

    private NettingCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new NettingCalculator;
    }

    /** The document's raw obligations, in grams × 1,000 = milligrams. */
    private function tradingDay(): array
    {
        return [
            new Obligation(1, self::A, self::B, 100_000),
            new Obligation(2, self::B, self::A, 70_000),
            new Obligation(3, self::A, self::C, 250_000),
            new Obligation(4, self::C, self::A, 30_000),
            new Obligation(5, self::B, self::C, 40_000),
            new Obligation(6, self::C, self::B, 90_000),
            new Obligation(7, self::A, self::D, 180_000),
            new Obligation(8, self::D, self::A, 200_000),
            new Obligation(9, self::B, self::E, 60_000),
            new Obligation(10, self::E, self::B, 20_000),
        ];
    }

    #[Test]
    public function f12_halves_ten_transfers_to_five(): void
    {
        $nets = $this->calculator->bilateralNets($this->tradingDay());

        $this->assertCount(5, $nets, 'Five pairs traded');
        $this->assertSame(5, $this->calculator->transferCount($nets), '50 % fewer transfers');

        $byPair = [];
        foreach ($nets as $net) {
            $byPair[$net->lowOrganizationId.':'.$net->highOrganizationId] = $net;
        }

        // A ↔ B : 100 − 70 = A → B 30
        $this->assertSame(self::A, $byPair['1:2']->payerOrganizationId());
        $this->assertSame(30_000, $byPair['1:2']->amount());

        // A ↔ C : 250 − 30 = A → C 220
        $this->assertSame(self::A, $byPair['1:3']->payerOrganizationId());
        $this->assertSame(220_000, $byPair['1:3']->amount());

        // B ↔ C : 40 − 90 = C → B 50
        $this->assertSame(self::C, $byPair['2:3']->payerOrganizationId());
        $this->assertSame(self::B, $byPair['2:3']->receiverOrganizationId());
        $this->assertSame(50_000, $byPair['2:3']->amount());

        // A ↔ D : 180 − 200 = D → A 20
        $this->assertSame(self::D, $byPair['1:4']->payerOrganizationId());
        $this->assertSame(20_000, $byPair['1:4']->amount());

        // B ↔ E : 60 − 20 = B → E 40
        $this->assertSame(self::B, $byPair['2:5']->payerOrganizationId());
        $this->assertSame(40_000, $byPair['2:5']->amount());
    }

    #[Test]
    public function f13_gives_every_member_one_position_summing_to_zero(): void
    {
        $positions = $this->calculator->netPositions($this->tradingDay());

        $expected = [
            self::A => -230_000,
            self::B => 40_000,
            self::C => 170_000,
            self::D => -20_000,
            self::E => 40_000,
        ];

        $this->assertCount(5, $positions);

        foreach ($positions as $position) {
            $this->assertSame(
                $expected[$position->organizationId],
                $position->net,
                'Net position of organisation '.$position->organizationId,
            );

            // Invariant N2, restated: net = gross_in − gross_out.
            $this->assertSame($position->grossIn - $position->grossOut, $position->net);
        }

        // Invariant N1.
        $this->calculator->assertBalanced($positions, 'GOLD');
        $this->assertSame(0, array_sum(array_map(
            static fn (NetPosition $p): int => $p->net,
            $positions,
        )));
    }

    #[Test]
    public function invariant_n3_holds_and_n4_matches_gross_settlement(): void
    {
        $obligations = $this->tradingDay();
        $positions = $this->calculator->netPositions($obligations);

        // N3: net_transfer_count ≤ gross_transfer_count.
        $this->assertLessThanOrEqual(
            count($obligations),
            $this->calculator->transferCount($positions),
        );

        // N4: the netted outcome equals the gross outcome, member by member.
        $gross = $this->calculator->grossOutcome($obligations);

        foreach ($positions as $position) {
            $this->assertSame(
                $gross[$position->organizationId],
                $position->net,
                'Netting must leave organisation '.$position->organizationId.' exactly where gross settlement would',
            );
        }

        $this->assertSame(1_040_000, $this->calculator->grossVolume($obligations));
        $this->assertSame(250_000, $this->calculator->netVolume($positions), '40,000 + 170,000 + 40,000');
    }

    #[Test]
    public function a_pair_that_cancels_out_moves_nothing(): void
    {
        $nets = $this->calculator->bilateralNets([
            new Obligation(1, self::A, self::B, 50_000),
            new Obligation(2, self::B, self::A, 50_000),
        ]);

        $this->assertCount(1, $nets);
        $this->assertTrue($nets[0]->isSettledOut());
        $this->assertNull($nets[0]->payerOrganizationId());
        $this->assertSame(0, $this->calculator->transferCount($nets), 'F12: تسویه کامل، بدون انتقال');
    }

    #[Test]
    public function a_flat_participant_needs_no_multilateral_transfer(): void
    {
        $positions = $this->calculator->netPositions([
            new Obligation(1, self::A, self::B, 100_000),
            new Obligation(2, self::B, self::C, 100_000),
            new Obligation(3, self::C, self::A, 100_000),
        ]);

        foreach ($positions as $position) {
            $this->assertTrue($position->isFlat(), 'A perfect three-way ring nets to nothing');
        }

        $this->assertSame(0, $this->calculator->transferCount($positions));
        $this->assertSame(0, $this->calculator->netVolume($positions));
        $this->assertSame(300_000, $this->calculator->grossVolume([
            new Obligation(1, self::A, self::B, 100_000),
            new Obligation(2, self::B, self::C, 100_000),
            new Obligation(3, self::C, self::A, 100_000),
        ]));
    }

    #[Test]
    public function an_unbalanced_set_of_positions_is_refused(): void
    {
        $this->expectException(NettingImbalanceException::class);

        $this->calculator->assertBalanced([
            new NetPosition(self::A, 100, 0, 100),
            new NetPosition(self::B, 0, 50, -50),
        ], 'GOLD');
    }

    #[Test]
    public function a_position_that_violates_n2_cannot_be_constructed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NetPosition(self::A, 100, 40, 99);
    }

    #[Test]
    public function an_obligation_must_be_positive_and_between_two_parties(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Obligation(1, self::A, self::A, 100);
    }

    #[Test]
    public function a_zero_obligation_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Obligation(1, self::A, self::B, 0);
    }

    #[Test]
    public function bilateral_nets_are_direction_independent(): void
    {
        $forward = $this->calculator->bilateralNets([
            new Obligation(1, self::A, self::B, 90_000),
            new Obligation(2, self::B, self::A, 40_000),
        ]);

        $reverse = $this->calculator->bilateralNets([
            new Obligation(2, self::B, self::A, 40_000),
            new Obligation(1, self::A, self::B, 90_000),
        ]);

        $this->assertEquals(
            array_map(static fn (BilateralNet $n): array => $n->toArray(), $forward),
            array_map(static fn (BilateralNet $n): array => $n->toArray(), $reverse),
            'The order obligations arrive in must not change the answer',
        );
    }
}
