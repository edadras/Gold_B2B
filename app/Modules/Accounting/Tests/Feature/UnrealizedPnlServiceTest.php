<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Tests\Feature;

use App\Modules\Accounting\Application\CostBasisService;
use App\Modules\Accounting\Application\UnrealizedPnlService;
use App\Modules\Accounting\Contracts\MarketPriceProvider;
use App\Modules\Accounting\Tests\AccountingTestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * F17, and the rule that matters more than the arithmetic: this figure is
 * informational and must never reach the journal.
 */
final class UnrealizedPnlServiceTest extends AccountingTestCase
{
    private const ORG = 184;

    #[Test]
    public function it_matches_the_documented_worked_example(): void
    {
        // 150,000 mg carried at 77,000,000/g → book value 11,550,000,000
        $this->app->make(CostBasisService::class)->recordPurchase(self::ORG, 150_000, 11_550_000_000);

        $pnl = $this->service()->forOrganization(self::ORG, 78_480_000);

        self::assertTrue($pnl->available);
        self::assertSame(11_550_000_000, $pnl->bookValueRial);
        self::assertSame(11_772_000_000, $pnl->marketValueRial);
        self::assertSame(222_000_000, $pnl->unrealizedRial);
        self::assertTrue($pnl->isGain());
    }

    #[Test]
    public function it_writes_nothing_to_the_journal_or_the_inventory(): void
    {
        $this->app->make(CostBasisService::class)->recordPurchase(self::ORG, 150_000, 11_550_000_000);

        $before = [
            'entries' => DB::table('journal_entries')->count(),
            'lines' => DB::table('journal_lines')->count(),
            'basis' => DB::table('inventory_cost_basis')->where('organization_id', self::ORG)->first(),
        ];

        $this->service()->forOrganization(self::ORG, 78_480_000);
        $this->service()->forOrganization(self::ORG, 120_000_000);
        $this->service()->asOf(self::ORG, '2026-01-20');

        self::assertSame($before['entries'], DB::table('journal_entries')->count());
        self::assertSame($before['lines'], DB::table('journal_lines')->count());
        self::assertEquals(
            $before['basis'],
            DB::table('inventory_cost_basis')->where('organization_id', self::ORG)->first(),
        );
    }

    #[Test]
    public function an_unknown_price_reports_unavailable_rather_than_zero(): void
    {
        $this->app->make(CostBasisService::class)->recordPurchase(self::ORG, 150_000, 11_550_000_000);

        // The default binding is the null provider: no feed is wired in.
        $pnl = $this->service()->forOrganization(self::ORG);

        self::assertFalse($pnl->available);
        self::assertNull($pnl->marketPricePerGram);
        self::assertSame(0, $pnl->unrealizedRial);
        self::assertFalse($pnl->isGain(), 'an unknown position is not a gain');
        self::assertSame(11_550_000_000, $pnl->bookValueRial, 'the book value is still known');
    }

    #[Test]
    public function a_falling_market_produces_a_negative_figure(): void
    {
        $this->app->make(CostBasisService::class)->recordPurchase(self::ORG, 150_000, 11_550_000_000);

        $pnl = $this->service()->forOrganization(self::ORG, 70_000_000);

        self::assertSame(10_500_000_000, $pnl->marketValueRial);
        self::assertSame(-1_050_000_000, $pnl->unrealizedRial);
        self::assertFalse($pnl->isGain());
    }

    #[Test]
    public function a_bound_price_provider_is_used_when_no_price_is_passed(): void
    {
        $this->app->make(CostBasisService::class)->recordPurchase(self::ORG, 150_000, 11_550_000_000);

        $this->app->instance(MarketPriceProvider::class, new class implements MarketPriceProvider
        {
            public function currentPricePerFineGram(): ?int
            {
                return 78_480_000;
            }

            public function closingPricePerFineGram(string $date): ?int
            {
                return 78_100_000;
            }
        });

        self::assertSame(222_000_000, $this->service()->forOrganization(self::ORG)->unrealizedRial);
        self::assertSame(
            11_715_000_000 - 11_550_000_000,
            $this->service()->asOf(self::ORG, '2026-01-20')->unrealizedRial,
        );
    }

    private function service(): UnrealizedPnlService
    {
        return $this->app->make(UnrealizedPnlService::class);
    }
}
