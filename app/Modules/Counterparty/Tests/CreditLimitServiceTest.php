<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Tests;

use App\Modules\Counterparty\Application\CreditLimitService;
use App\Modules\Shared\Exceptions\LimitExceededException;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use PHPUnit\Framework\Attributes\Test;

/** §10.5 — the limit I grant one counterparty, and what is left of it. */
final class CreditLimitServiceTest extends CounterpartyTestCase
{
    private CreditLimitService $limits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->limits = $this->app->make(CreditLimitService::class);
    }

    #[Test]
    public function limits_can_be_set_before_the_first_trade(): void
    {
        $headroom = $this->limits->setLimits(101, 202, goldLimitMg: 500_000, rialLimit: 5_000_000_000);

        self::assertSame(500_000, $headroom->goldLimitMg);
        self::assertSame(0, $headroom->goldUsedMg);
        self::assertSame(500_000, $headroom->goldRemainingMg);
        self::assertSame(5_000_000_000, $headroom->rialRemaining);

        // The pair is materialised in both directions, still at zero.
        self::assertSame(0, $this->goldBalance(101, 202));
        self::assertSame(0, $this->goldBalance(202, 101));
    }

    #[Test]
    public function a_receivable_consumes_headroom_and_a_payable_does_not(): void
    {
        $this->limits->setLimits(101, 202, goldLimitMg: 500_000, rialLimit: 5_000_000_000);

        // They owe us 300 g: 300 g of our limit is in use.
        $this->relations->applyTrade(101, 202, 300_000, 0);
        $headroom = $this->limits->remainingHeadroom(101, 202);

        self::assertSame(300_000, $headroom->goldUsedMg);
        self::assertSame(200_000, $headroom->goldRemainingMg);
        self::assertTrue($headroom->allowsAdditionalGold(200_000));
        self::assertFalse($headroom->allowsAdditionalGold(200_001));

        // We owe them instead: none of *our* limit is used.
        $this->relations->applyTrade(101, 202, -600_000, 0);
        $headroom = $this->limits->remainingHeadroom(101, 202);

        self::assertSame(-300_000, $this->goldBalance(101, 202));
        self::assertSame(0, $headroom->goldUsedMg);
        self::assertSame(500_000, $headroom->goldRemainingMg);
    }

    #[Test]
    public function limits_are_one_directional(): void
    {
        $this->limits->setLimits(101, 202, goldLimitMg: 500_000);

        self::assertSame(500_000, $this->limits->limits(101, 202)['gold_limit_mg']);
        self::assertSame(0, $this->limits->limits(202, 101)['gold_limit_mg']);
    }

    #[Test]
    public function a_trade_beyond_the_limit_is_flagged_and_raises(): void
    {
        $this->limits->setLimits(101, 202, goldLimitMg: 500_000, rialLimit: 1_000);
        $this->relations->applyTrade(101, 202, 400_000, 0);

        self::assertFalse($this->limits->wouldExceed(101, 202, 100_000, 0));
        self::assertTrue($this->limits->wouldExceed(101, 202, 100_001, 0));

        $this->limits->assertWithinLimits(101, 202, 100_000, 0);

        try {
            $this->limits->assertWithinLimits(101, 202, 100_001, 0);
            self::fail('expected the limit breach to raise');
        } catch (LimitExceededException $e) {
            self::assertSame('counterparty_gold_credit', $e->limitType);
            self::assertSame(500_001, $e->requested);
            self::assertSame(500_000, $e->limit);
        }
    }

    #[Test]
    public function a_negative_limit_is_rejected(): void
    {
        $this->expectException(OperationNotPermittedException::class);

        $this->limits->setLimits(101, 202, goldLimitMg: -1);
    }

    #[Test]
    public function setting_one_limit_leaves_the_other_alone(): void
    {
        $this->limits->setLimits(101, 202, goldLimitMg: 500_000, rialLimit: 5_000_000_000);
        $this->limits->setLimits(101, 202, goldLimitMg: 700_000);

        $limits = $this->limits->limits(101, 202);

        self::assertSame(700_000, $limits['gold_limit_mg']);
        self::assertSame(5_000_000_000, $limits['rial_limit']);
    }
}
