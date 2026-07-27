<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Tests\Feature;

use App\Modules\Ledger\Contracts\GroupWriter;
use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\EntryType;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Ledger\Domain\SystemAccountCode;
use App\Modules\Ledger\Tests\LedgerTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * docs/11-appendix/03-worked-examples.md, example 1 — end to end.
 *
 * Organisation 184 sells 250 g fine to 291 at 78,480,000 rial/g. Every figure
 * asserted here is copied from the document, so if the ledger ever produces a
 * different number the example and the code have diverged.
 */
final class WorkedExampleOneTest extends LedgerTestCase
{
    use RefreshDatabase;

    private const SELLER = 184;

    private const BUYER = 291;

    // Figures from the example.
    private const FINE_MG = 250_000;

    private const BUYER_RESERVE = 19_654_437_500;

    private const SURPLUS = 5_007_500;

    private const BUYER_NET = 19_649_430_000;

    private const SELLER_NET = 19_600_380_000;

    private const BUYER_FEE = 29_430_000;

    private const SELLER_FEE = 19_620_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLedger(self::SELLER, self::BUYER);

        // Opening state of the example.
        $this->depositGold(self::SELLER, 1_000_000);
        $this->depositRial(self::SELLER, 500_000_000);
        $this->depositRial(self::BUYER, 25_000_000_000);
    }

    #[Test]
    public function the_full_trade_reproduces_every_figure_in_the_document(): void
    {
        $order = LedgerReference::order(44101);
        $buyOrder = LedgerReference::order(44120);
        $settlement = LedgerReference::settlement(88231);

        // g1 — the seller's order reserves the gold.
        $goldReservation = $this->goldLedger()->reserve(self::SELLER, self::fine(self::FINE_MG), $order);
        $this->assertSame(750_000, $this->goldBalance(self::SELLER));
        $this->assertSame(250_000, $this->goldBalance(self::SELLER, Bucket::RESERVED));

        // g2 — the buyer reserves cash at their own limit price.
        $cashReservation = $this->rialLedger()->reserve(self::BUYER, self::money(self::BUYER_RESERVE), $buyOrder);
        $this->assertSame(5_345_562_500, $this->rialBalance(self::BUYER));
        $this->assertSame(self::BUYER_RESERVE, $this->rialBalance(self::BUYER, Bucket::RESERVED));

        // g3 — execution happens at the maker's price, so the surplus goes back.
        $this->rialLedger()->release($cashReservation, self::money(self::SURPLUS));
        $this->assertSame(self::BUYER_NET, $this->rialBalance(self::BUYER, Bucket::RESERVED));
        $this->assertSame(5_350_570_000, $this->rialBalance(self::BUYER));

        // g4 — both sides lock their assets for settlement.
        $this->goldLedger()->moveBucket(
            self::SELLER, Bucket::RESERVED, Bucket::IN_SETTLEMENT, self::fine(self::FINE_MG), $settlement
        );
        $this->rialLedger()->moveBucket(
            self::BUYER, Bucket::RESERVED, Bucket::IN_SETTLEMENT, self::money(self::BUYER_NET), $settlement
        );
        $this->assertSame(0, $this->goldBalance(self::SELLER, Bucket::RESERVED));
        $this->assertSame(0, $this->rialBalance(self::BUYER, Bucket::RESERVED));

        // g5 — cash moves and both fees are recognised, in one balanced group.
        $g5 = $this->ledger()->postGroup(function (GroupWriter $writer) use ($settlement): void {
            $writer->post(self::BUYER, AssetType::RIAL, Bucket::IN_SETTLEMENT, -self::BUYER_NET, EntryType::TRADE_BUY_CASH, $settlement);
            $writer->post(self::SELLER, AssetType::RIAL, Bucket::AVAILABLE, self::SELLER_NET, EntryType::TRADE_SELL_CASH, $settlement);
            $writer->postSystem(SystemAccountCode::FEE_INCOME, AssetType::RIAL, self::BUYER_FEE, EntryType::FEE_INCOME, $settlement);
            $writer->postSystem(SystemAccountCode::FEE_INCOME, AssetType::RIAL, self::SELLER_FEE, EntryType::FEE_INCOME, $settlement);
        });
        $this->assertGroupSumsToZero($g5->value);

        // g6 — the gold changes owner. Nothing physically moves.
        $this->goldLedger()->transfer(
            self::SELLER,
            self::BUYER,
            self::fine(self::FINE_MG),
            LedgerReference::trade(88231),
            Bucket::IN_SETTLEMENT,
            Bucket::AVAILABLE,
        );

        // ── final state, straight from the document ──────────────────────────
        $this->assertSame(750_000, $this->goldBalance(self::SELLER));
        $this->assertSame(0, $this->goldBalance(self::SELLER, Bucket::RESERVED));
        $this->assertSame(0, $this->goldBalance(self::SELLER, Bucket::IN_SETTLEMENT));
        $this->assertSame(20_100_380_000, $this->rialBalance(self::SELLER));

        $this->assertSame(250_000, $this->goldBalance(self::BUYER));
        $this->assertSame(5_350_570_000, $this->rialBalance(self::BUYER));

        $this->assertSame(49_050_000, $this->systemAccountBalance(SystemAccountCode::FEE_INCOME, AssetType::RIAL));

        // ── conservation of mass, as the document checks it ──────────────────
        $this->assertSame(
            1_000_000,
            $this->goldBalance(self::SELLER) + $this->goldBalance(self::BUYER),
            'Gold before: 1,000,000 mg — gold after must match',
        );
        $this->assertSame(
            25_500_000_000,
            $this->rialBalance(self::SELLER)
                + $this->rialBalance(self::BUYER)
                + $this->systemAccountBalance(SystemAccountCode::FEE_INCOME, AssetType::RIAL),
            'Rial before: 25,500,000,000 — rial after must match',
        );

        // ── the invariants ───────────────────────────────────────────────────
        $this->assertSame(0, $this->systemTotal(AssetType::GOLD));
        $this->assertSame(0, $this->systemTotal(AssetType::RIAL));
        $this->assertTrue($this->reconciliation()->reconcile(null, true)['healthy']);

        $goldReservationRow = DB::table('ledger_entries')->where('id', $goldReservation->value)->first();
        $this->assertSame(EntryType::RESERVE->value, $goldReservationRow->entry_type);
    }

    #[Test]
    public function the_trade_flow_writes_six_balanced_transaction_groups(): void
    {
        $this->the_full_trade_reproduces_every_figure_in_the_document();

        $groups = DB::table('ledger_entries')
            ->where('reference_type', '!=', 'custody')   // exclude the opening deposits
            ->distinct()
            ->pluck('transaction_group');

        // g1..g6. The document's g4 covers two organisations and g5 four legs,
        // so the entry total is 16 rather than the 12 quoted in its closing
        // line — the per-group tables in the document add up to 16.
        $this->assertCount(7, $groups, 'g1, g2, g3, g4 (×2 orgs), g5, g6');

        foreach ($groups as $group) {
            $this->assertGroupSumsToZero((string) $group);
        }

        $this->assertSame(
            16,
            DB::table('ledger_entries')->where('reference_type', '!=', 'custody')->count(),
        );
    }

    private function systemAccountBalance(SystemAccountCode $code, AssetType $asset): int
    {
        return (int) DB::table('ledger_accounts as a')
            ->join('ledger_balances as b', 'b.account_id', '=', 'a.id')
            ->where('a.system_account_code', $code->value)
            ->where('a.asset_type', $asset->value)
            ->value('b.balance');
    }
}
