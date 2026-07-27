<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Tests\Feature;

use App\Modules\Accounting\Application\CostBasisService;
use App\Modules\Accounting\Domain\Exceptions\InsufficientInventoryException;
use App\Modules\Accounting\Tests\AccountingTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * F14/F15/F16 against the worked numbers printed in the formulas appendix.
 *
 * These constants are not arbitrary fixtures — they are the exact figures in
 * docs/11-appendix/01-formulas.md §1.7, so a failure here means the
 * implementation and the specification have diverged.
 */
final class CostBasisServiceTest extends AccountingTestCase
{
    private const ORG = 184;

    private CostBasisService $costBasis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->costBasis = $this->app->make(CostBasisService::class);
    }

    #[Test]
    public function weighted_average_matches_the_documented_worked_example(): void
    {
        // old: 100,000 mg @ avg 75,000,000 ► total 7,500,000,000
        $opening = $this->costBasis->recordPurchase(self::ORG, 100_000, 7_500_000_000);

        self::assertSame(100_000, $opening->quantityMg);
        self::assertSame(75_000_000, $opening->averageCostPerGram);

        // buy: 200,000 mg cost 15,600,000,000
        $after = $this->costBasis->recordPurchase(self::ORG, 200_000, 15_600_000_000);

        self::assertSame(300_000, $after->quantityMg);
        self::assertSame(23_100_000_000, $after->totalCostRial);
        self::assertSame(77_000_000, $after->averageCostPerGram);
    }

    #[Test]
    public function a_sale_reports_the_documented_cogs_and_profit(): void
    {
        $this->costBasis->recordPurchase(self::ORG, 100_000, 7_500_000_000);
        $this->costBasis->recordPurchase(self::ORG, 200_000, 15_600_000_000);

        // sold = 150,000 mg for 12,000,000,000 with a 12,000,000 fee
        $sale = $this->costBasis->recordSale(self::ORG, 150_000, 12_000_000_000, 12_000_000);

        self::assertSame(11_550_000_000, $sale->costOfGoodsSold, 'F15');
        self::assertSame(450_000_000, $sale->grossProfit, 'F16 gross');
        self::assertSame(438_000_000, $sale->realizedProfit, 'F16 net of fees');
    }

    #[Test]
    public function selling_does_not_move_the_average(): void
    {
        $this->costBasis->recordPurchase(self::ORG, 100_000, 7_500_000_000);
        $this->costBasis->recordPurchase(self::ORG, 200_000, 15_600_000_000);

        $sale = $this->costBasis->recordSale(self::ORG, 150_000, 12_000_000_000, 12_000_000);

        self::assertSame(77_000_000, $sale->averageCostPerGram);

        $after = $this->costBasis->snapshot(self::ORG);

        self::assertSame(77_000_000, $after->averageCostPerGram, 'a sale must never revalue inventory');
        self::assertSame(150_000, $after->quantityMg);
        self::assertSame(11_550_000_000, $after->totalCostRial);
    }

    #[Test]
    public function a_second_purchase_at_a_different_price_moves_the_average_but_a_sale_between_them_does_not(): void
    {
        $this->costBasis->recordPurchase(self::ORG, 100_000, 7_500_000_000);   // 75,000,000/g

        $this->costBasis->recordSale(self::ORG, 40_000, 3_200_000_000);
        self::assertSame(75_000_000, $this->costBasis->snapshot(self::ORG)->averageCostPerGram);

        // 60,000 mg @ 75,000,000 = 4,500,000,000 left; buy 40,000 mg for 3,400,000,000
        $after = $this->costBasis->recordPurchase(self::ORG, 40_000, 3_400_000_000);

        self::assertSame(100_000, $after->quantityMg);
        self::assertSame(7_900_000_000, $after->totalCostRial);
        self::assertSame(79_000_000, $after->averageCostPerGram);
    }

    #[Test]
    public function the_average_floors_rather_than_rounds(): void
    {
        // 3 mg costing 1,000 rial → 1000 * 1000 / 3 = 333,333.33… → 333,333
        $snapshot = $this->costBasis->recordPurchase(self::ORG, 3, 1_000);

        self::assertSame(333_333, $snapshot->averageCostPerGram);
    }

    #[Test]
    public function selling_the_last_milligram_leaves_no_cost_stranded(): void
    {
        $this->costBasis->recordPurchase(self::ORG, 3, 1_000);

        $sale = $this->costBasis->recordSale(self::ORG, 3, 1_200);

        self::assertSame(1_000, $sale->costOfGoodsSold, 'the final sale absorbs the rounding remainder');
        self::assertSame(0, $sale->remainingQuantityMg);
        self::assertSame(0, $sale->remainingCostRial);
        self::assertSame(200, $sale->realizedProfit);
    }

    #[Test]
    public function selling_more_than_is_held_is_refused(): void
    {
        $this->costBasis->recordPurchase(self::ORG, 100_000, 7_500_000_000);

        $this->expectException(InsufficientInventoryException::class);

        $this->costBasis->recordSale(self::ORG, 100_001, 8_000_000_000);
    }

    #[Test]
    public function a_downward_assay_adjustment_raises_the_average_without_changing_the_cost(): void
    {
        $this->costBasis->recordPurchase(self::ORG, 100_000, 7_500_000_000);

        $after = $this->costBasis->recordQuantityAdjustment(self::ORG, -5_000);

        self::assertSame(95_000, $after->quantityMg);
        self::assertSame(7_500_000_000, $after->totalCostRial, 'the money was already spent');
        self::assertSame(78_947_368, $after->averageCostPerGram);
    }
}
