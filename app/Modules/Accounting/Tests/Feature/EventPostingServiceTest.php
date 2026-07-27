<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Tests\Feature;

use App\Modules\Accounting\Application\CostBasisService;
use App\Modules\Accounting\Application\EventPostingService;
use App\Modules\Accounting\Tests\AccountingTestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * The half of idempotency that `uq_source` cannot cover on its own.
 *
 * A redelivered trade must not fold the same purchase into the weighted average
 * a second time — the voucher would be deduplicated, but the cost basis would
 * be silently wrong from then on.
 */
final class EventPostingServiceTest extends AccountingTestCase
{
    private const ORG = 184;

    private EventPostingService $posting;

    private CostBasisService $costBasis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->posting = $this->app->make(EventPostingService::class);
        $this->costBasis = $this->app->make(CostBasisService::class);
    }

    #[Test]
    public function a_redelivered_purchase_does_not_move_the_cost_basis_twice(): void
    {
        $post = fn (): object => $this->posting->postPurchase(
            organizationId: self::ORG,
            tradeId: 88_231,
            fineMg: 100_000,
            grossRial: 7_500_000_000,
            feeRial: 11_250_000,
            entryDate: '2026-01-20',
        );

        $first = $post();
        $second = $post();
        $third = $post();

        self::assertTrue($first->wasCreated());
        self::assertTrue($second->alreadyExisted);
        self::assertTrue($third->alreadyExisted);

        $basis = $this->costBasis->snapshot(self::ORG);

        self::assertSame(100_000, $basis->quantityMg, 'the purchase was counted once');
        self::assertSame(7_500_000_000, $basis->totalCostRial);
        self::assertSame(75_000_000, $basis->averageCostPerGram);

        self::assertSame(1, DB::table('journal_entries')->count());
    }

    #[Test]
    public function a_redelivered_sale_does_not_consume_inventory_twice(): void
    {
        $this->costBasis->recordPurchase(self::ORG, 300_000, 23_100_000_000);

        $sell = fn (): object => $this->posting->postSale(
            organizationId: self::ORG,
            tradeId: 88_232,
            fineMg: 150_000,
            grossRial: 12_000_000_000,
            feeRial: 12_000_000,
            entryDate: '2026-01-20',
        );

        $sell();
        $sell();

        $basis = $this->costBasis->snapshot(self::ORG);

        self::assertSame(150_000, $basis->quantityMg);
        self::assertSame(11_550_000_000, $basis->totalCostRial);
        self::assertSame(77_000_000, $basis->averageCostPerGram);
    }

    #[Test]
    public function a_sale_voucher_carries_the_cogs_the_cost_basis_computed(): void
    {
        $this->costBasis->recordPurchase(self::ORG, 300_000, 23_100_000_000);

        $result = $this->posting->postSale(
            organizationId: self::ORG,
            tradeId: 88_233,
            fineMg: 150_000,
            grossRial: 12_000_000_000,
            feeRial: 12_000_000,
            entryDate: '2026-01-20',
        );

        $cogsLine = DB::table('journal_lines')
            ->where('journal_entry_id', $result->journalEntryId)
            ->where('account_code', '5101')
            ->first();

        self::assertNotNull($cogsLine);
        self::assertSame(11_550_000_000, (int) $cogsLine->debit_rial);
    }

    #[Test]
    public function a_failed_voucher_rolls_back_the_inventory_change(): void
    {
        // A sale of more gold than the cost basis holds must leave nothing behind.
        $this->costBasis->recordPurchase(self::ORG, 10_000, 750_000_000);

        try {
            $this->posting->postSale(
                organizationId: self::ORG,
                tradeId: 88_234,
                fineMg: 20_000,
                grossRial: 1_600_000_000,
                feeRial: 0,
                entryDate: '2026-01-20',
            );
            self::fail('overselling should have been refused');
        } catch (\Throwable) {
            // expected
        }

        $basis = $this->costBasis->snapshot(self::ORG);

        self::assertSame(10_000, $basis->quantityMg);
        self::assertSame(750_000_000, $basis->totalCostRial);
        self::assertSame(0, DB::table('journal_entries')->count());
    }
}
