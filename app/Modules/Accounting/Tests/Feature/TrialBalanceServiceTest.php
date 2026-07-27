<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Tests\Feature;

use App\Modules\Accounting\Application\PostingRules;
use App\Modules\Accounting\Application\TrialBalanceService;
use App\Modules\Accounting\Contracts\JournalPosterInterface;
use App\Modules\Accounting\Contracts\PostingContext;
use App\Modules\Accounting\Domain\AccountCode;
use App\Modules\Accounting\Domain\BalanceSide;
use App\Modules\Accounting\Domain\PostingRule;
use App\Modules\Accounting\Domain\SourceType;
use App\Modules\Accounting\Tests\AccountingTestCase;
use PHPUnit\Framework\Attributes\Test;

final class TrialBalanceServiceTest extends AccountingTestCase
{
    private const ORG = 184;

    #[Test]
    public function it_foots_and_reports_each_account_on_the_right_side(): void
    {
        $poster = $this->app->make(JournalPosterInterface::class);
        $rules = $this->app->make(PostingRules::class);

        $poster->post($rules->build(PostingRule::PURCHASE, new PostingContext(
            organizationId: self::ORG,
            sourceId: 1,
            entryDate: '2026-01-05',
            sourceType: SourceType::TRADE,
            fineMg: 248_750,
            grossRial: 19_521_900_000,
            feeRial: 29_282_850,
        )));

        $poster->post($rules->build(PostingRule::SALE, new PostingContext(
            organizationId: self::ORG,
            sourceId: 2,
            entryDate: '2026-01-06',
            sourceType: SourceType::TRADE,
            fineMg: 150_000,
            grossRial: 12_000_000_000,
            feeRial: 12_000_000,
            cogsRial: 11_550_000_000,
        )));

        $balance = $this->app->make(TrialBalanceService::class)
            ->build(self::ORG, '2026-01-01', '2026-01-31');

        self::assertTrue($balance->isBalanced());
        self::assertSame(0, $balance->rialDifference());
        self::assertSame(0, $balance->goldDifference());

        $sales = $balance->row('4101');
        self::assertNotNull($sales);
        self::assertSame(BalanceSide::CREDIT, $sales->side());
        self::assertSame(12_000_000_000, $sales->balanceRial());

        $cogs = $balance->row('5101');
        self::assertNotNull($cogs);
        self::assertSame(BalanceSide::DEBIT, $cogs->side());
        self::assertSame(11_550_000_000, $cogs->balanceRial());

        // Gold in the vault: 248.750 g in, 150.000 g out.
        $vault = $balance->row('1110');
        self::assertNotNull($vault);
        self::assertSame(98_750, $vault->netFineMg());
    }

    #[Test]
    public function a_date_range_outside_the_postings_returns_an_empty_but_balanced_report(): void
    {
        $balance = $this->app->make(TrialBalanceService::class)
            ->build(self::ORG, '2020-01-01', '2020-01-31');

        self::assertSame([], $balance->rows);
        self::assertTrue($balance->isBalanced());
    }

    #[Test]
    public function net_movement_on_a_single_account_is_readable(): void
    {
        $poster = $this->app->make(JournalPosterInterface::class);
        $rules = $this->app->make(PostingRules::class);

        $poster->post($rules->build(PostingRule::FEE, new PostingContext(
            organizationId: self::ORG,
            sourceId: 3,
            entryDate: '2026-01-07',
            sourceType: SourceType::FEE,
            feeRial: 7_000_000,
        )));

        $service = $this->app->make(TrialBalanceService::class);

        self::assertSame(
            7_000_000,
            $service->netRialOn(self::ORG, AccountCode::PLATFORM_FEE, '2026-01-01', '2026-01-31'),
        );
        self::assertSame(
            -7_000_000,
            $service->netRialOn(self::ORG, AccountCode::PLATFORM_RIAL_BALANCE, '2026-01-01', '2026-01-31'),
        );
    }
}
