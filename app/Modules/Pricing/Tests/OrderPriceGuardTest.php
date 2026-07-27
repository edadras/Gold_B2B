<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Tests;

use App\Modules\Pricing\Application\OrderPriceGuard;
use App\Modules\Pricing\Domain\PriceGuardStatus;
use App\Modules\Pricing\Exceptions\PriceOutOfBandException;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Fat-finger guard, docs/03-domain/07-pricing.md §7.8. */
final class OrderPriceGuardTest extends TestCase
{
    #[Test]
    public function a_normal_price_passes(): void
    {
        $decision = $this->guard()->check(1, PricePerFineGram::fromRial(78_500_000));

        $this->assertSame(PriceGuardStatus::ACCEPTED, $decision->status);
        $this->assertFalse($decision->isUnusual());
    }

    #[Test]
    public function beyond_the_warn_threshold_it_warns_and_demands_confirmation(): void
    {
        // floor(8,000,000 × 10,000 / 78,000,000) = 1,025 bps, past the 1,000 bps warn line.
        $decision = $this->guard()->check(1, PricePerFineGram::fromRial(86_000_000));

        $this->assertSame(PriceGuardStatus::WARN, $decision->status);
        $this->assertSame(1_025, $decision->deviationBps);
        $this->assertTrue($decision->status->requiresExplicitConfirmation());
        $this->assertTrue($decision->isUnusual(), 'audit must carry the UNUSUAL_PRICE marker');
    }

    #[Test]
    public function beyond_the_hard_threshold_it_rejects(): void
    {
        // floor(17,000,000 × 10,000 / 78,000,000) = 2,179 bps, past the 2,000 bps line.
        $decision = $this->guard()->check(1, PricePerFineGram::fromRial(95_000_000));

        $this->assertSame(PriceGuardStatus::REJECTED, $decision->status);
        $this->assertSame(2_179, $decision->deviationBps);
    }

    #[Test]
    public function assert_throws_only_on_hard_rejection(): void
    {
        $guard = $this->guard();

        $this->assertSame(
            PriceGuardStatus::WARN,
            $guard->assert(1, PricePerFineGram::fromRial(86_000_000))->status,
        );

        $this->expectException(PriceOutOfBandException::class);
        $guard->assert(1, PricePerFineGram::fromRial(95_000_000));
    }

    #[Test]
    public function it_prefers_the_last_traded_price_over_the_intrinsic_value(): void
    {
        // Intrinsic sits ~48% below the market in Iran (§7.5); measuring against
        // it would reject every ordinary order.
        $guard = new OrderPriceGuard(new FixedPriceReader(
            reference: 52_831_649,
            last: 78_480_000,
        ));

        $decision = $guard->check(1, PricePerFineGram::fromRial(78_500_000));

        $this->assertSame(PriceGuardStatus::ACCEPTED, $decision->status);
        $this->assertSame(78_480_000, $decision->referenceRial);
    }

    #[Test]
    public function without_any_reference_the_guard_stands_down(): void
    {
        $guard = new OrderPriceGuard(new FixedPriceReader);

        $decision = $guard->check(1, PricePerFineGram::fromRial(1));

        $this->assertSame(PriceGuardStatus::ACCEPTED, $decision->status);
        $this->assertSame(0, $decision->referenceRial);
    }

    private function guard(): OrderPriceGuard
    {
        return new OrderPriceGuard(new FixedPriceReader(last: 78_000_000));
    }
}
