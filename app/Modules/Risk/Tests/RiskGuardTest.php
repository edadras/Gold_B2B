<?php

declare(strict_types=1);

namespace App\Modules\Risk\Tests;

use App\Modules\Risk\Application\DailyCounters;
use App\Modules\Risk\Contracts\OrganizationStatusReaderInterface;
use App\Modules\Risk\Contracts\RiskGuardInterface;
use App\Modules\Risk\Contracts\TradeIntent;
use App\Modules\Risk\Contracts\TradeSide;
use App\Modules\Risk\Contracts\TradingExposureReaderInterface;
use App\Modules\Risk\Database\Factories\RiskProfileFactory;
use App\Modules\Risk\Domain\LimitType;
use App\Modules\Risk\Domain\RiskLevel;
use App\Modules\Risk\Events\LimitExceeded;
use App\Modules\Risk\Exceptions\LicenseExpiredException;
use App\Modules\Risk\Exceptions\OrganizationNotActiveException;
use App\Modules\Risk\Exceptions\OutsideTradingHoursException;
use App\Modules\Risk\Exceptions\RiskProfileNotFoundException;
use App\Modules\Risk\Exceptions\SettlementTypeNotAllowedException;
use App\Modules\Risk\Exceptions\TradingNotAllowedException;
use App\Modules\Risk\Infrastructure\Models\RiskProfile;
use App\Modules\Risk\Infrastructure\Models\UserLimit;
use App\Modules\Shared\Exceptions\LimitExceededException;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Rial;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The eleven pre-trade checks of docs/03-domain/11-risk-credit.md §11.4. */
final class RiskGuardTest extends TestCase
{
    use RefreshDatabase;

    private const ORG = 4_101;

    private const USER = 9_001;

    private const COUNTERPARTY = 4_202;

    private FakeTradingExposureReader $exposures;

    private FakeOrganizationStatusReader $organizations;

    protected function setUp(): void
    {
        parent::setUp();

        // 12:30 Tehran — comfortably inside the 09:00–17:30 market window.
        CarbonImmutable::setTestNow('2026-01-05 09:00:00');

        $this->exposures = new FakeTradingExposureReader;
        $this->organizations = new FakeOrganizationStatusReader;

        $this->app->instance(TradingExposureReaderInterface::class, $this->exposures);
        $this->app->instance(OrganizationStatusReaderInterface::class, $this->organizations);

        $this->counters()->reset(self::ORG, self::USER);
    }

    protected function tearDown(): void
    {
        $this->counters()->reset(self::ORG, self::USER);
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function a_normal_intent_is_allowed(): void
    {
        $this->profile();

        $this->guard()->assertTradeAllowed($this->intent());

        $this->assertTrue($this->guard()->evaluate($this->intent())->allowed);
    }

    #[Test]
    public function check_one_rejects_a_member_whose_trading_is_switched_off(): void
    {
        $this->profile(['is_trading_allowed' => false, 'restriction_reason' => 'COMPLIANCE_REVIEW']);

        $this->expectException(TradingNotAllowedException::class);
        $this->guard()->assertTradeAllowed($this->intent());
    }

    #[Test]
    public function check_two_rejects_a_member_that_is_not_active(): void
    {
        $this->profile();
        $this->organizations->status = 'SUSPENDED';

        $this->expectException(OrganizationNotActiveException::class);
        $this->guard()->assertTradeAllowed($this->intent());
    }

    #[Test]
    public function check_three_rejects_an_expired_licence(): void
    {
        $this->profile();
        $this->organizations->licenseExpiresAt = CarbonImmutable::now()->subDay();

        $this->expectException(LicenseExpiredException::class);
        $this->guard()->assertTradeAllowed($this->intent());
    }

    #[Test]
    public function check_four_rejects_an_oversized_order(): void
    {
        $this->profile(['max_order_mg' => 500_000]);

        $decision = $this->guard()->evaluate($this->intent(fineWeightMg: 600_000));

        $this->assertTrue($decision->isRejected());
        $this->assertSame(LimitType::PER_ORDER, $decision->limitType);
        $this->assertSame(600_000, $decision->requested);
        $this->assertSame(500_000, $decision->limit);
    }

    #[Test]
    public function check_five_rejects_when_the_daily_volume_would_be_exceeded(): void
    {
        $this->profile(['max_daily_volume_mg' => 1_000_000]);
        $this->counters()->increment(self::ORG, FineWeight::fromMilligrams(900_000), Rial::fromRial(1));

        $decision = $this->guard()->evaluate($this->intent(fineWeightMg: 200_000));

        $this->assertSame(LimitType::DAILY_VOLUME, $decision->limitType);
        $this->assertSame(1_100_000, $decision->requested);
    }

    #[Test]
    public function check_six_rejects_when_too_many_orders_are_already_open(): void
    {
        $this->profile(['max_open_orders' => 3]);
        $this->exposures->openOrders = 3;

        $decision = $this->guard()->evaluate($this->intent());

        $this->assertSame(LimitType::OPEN_ORDERS, $decision->limitType);
        $this->assertSame(3, $decision->limit);
    }

    #[Test]
    public function check_seven_rejects_when_gold_exposure_would_be_exceeded(): void
    {
        $this->profile(['max_open_exposure_mg' => 1_000_000]);
        $this->exposures->goldMg = 950_000;

        $decision = $this->guard()->evaluate($this->intent(fineWeightMg: 100_000));

        $this->assertSame(LimitType::OPEN_EXPOSURE, $decision->limitType);
        $this->assertSame(1_050_000, $decision->requested);
    }

    #[Test]
    public function check_seven_also_covers_the_rial_leg(): void
    {
        $this->profile([
            'max_open_exposure_mg' => 100_000_000,
            'max_open_exposure_rial' => 1_000_000_000,
        ]);

        // 100,000 mg at 78,480,000 rial/g is 7,848,000,000 rial of exposure.
        $decision = $this->guard()->evaluate($this->intent(fineWeightMg: 100_000));

        $this->assertSame(LimitType::OPEN_EXPOSURE_RIAL, $decision->limitType);
        $this->assertSame(7_848_000_000, $decision->requested);
    }

    #[Test]
    public function check_eight_rejects_a_settlement_type_the_member_may_not_use(): void
    {
        $this->profile(['allowed_settlement_types' => ['T0']]);

        $this->expectException(SettlementTypeNotAllowedException::class);
        $this->guard()->assertTradeAllowed($this->intent(settlementType: 'ON_ACCOUNT'));
    }

    #[Test]
    public function check_nine_rejects_too_much_volume_through_one_counterparty(): void
    {
        // Half of the 1,000,000 mg daily allowance may go to any one counterparty.
        $this->profile(['max_daily_volume_mg' => 1_000_000]);
        $this->exposures->counterpartyVolumeMg = 480_000;

        $decision = $this->guard()->evaluate($this->intent(
            fineWeightMg: 100_000,
            counterpartyOrgId: self::COUNTERPARTY,
        ));

        $this->assertSame(LimitType::COUNTERPARTY, $decision->limitType);
        $this->assertSame(580_000, $decision->requested);
        $this->assertSame(500_000, $decision->limit);
    }

    #[Test]
    public function check_nine_refuses_a_member_trading_with_itself(): void
    {
        $this->profile();

        $this->expectException(OperationNotPermittedException::class);
        $this->guard()->assertTradeAllowed($this->intent(counterpartyOrgId: self::ORG));
    }

    #[Test]
    public function check_ten_rejects_an_order_above_the_operator_ceiling(): void
    {
        $this->profile();
        $this->userLimit(['max_order_mg' => 50_000, 'max_daily_volume_mg' => 10_000_000]);

        $decision = $this->guard()->evaluate($this->intent(fineWeightMg: 100_000));

        $this->assertSame(LimitType::USER_ORDER, $decision->limitType);
        $this->assertSame(50_000, $decision->limit);
    }

    #[Test]
    public function check_ten_also_covers_the_operators_daily_volume(): void
    {
        $this->profile();
        $this->userLimit(['max_order_mg' => 1_000_000, 'max_daily_volume_mg' => 150_000]);
        $this->counters()->increment(
            self::ORG,
            FineWeight::fromMilligrams(100_000),
            Rial::fromRial(1),
            self::USER,
        );

        $decision = $this->guard()->evaluate($this->intent(fineWeightMg: 100_000));

        $this->assertSame(LimitType::USER_DAILY, $decision->limitType);
        $this->assertSame(200_000, $decision->requested);
    }

    #[Test]
    public function check_eleven_rejects_a_trade_outside_market_hours(): void
    {
        $this->profile();

        // 03:30 Tehran.
        $this->expectException(OutsideTradingHoursException::class);
        $this->guard()->assertTradeAllowed($this->intent(
            at: CarbonImmutable::parse('2026-01-05 00:00:00', 'UTC'),
        ));
    }

    #[Test]
    public function a_member_without_a_profile_is_refused(): void
    {
        $this->expectException(RiskProfileNotFoundException::class);
        $this->guard()->assertTradeAllowed($this->intent());
    }

    #[Test]
    public function the_cheapest_failing_check_is_the_one_reported(): void
    {
        // Every one of these would fail on its own; check 1 must win.
        $this->profile([
            'is_trading_allowed' => false,
            'restriction_reason' => 'COMPLIANCE_REVIEW',
            'max_order_mg' => 1,
            'max_daily_volume_mg' => 1,
            'max_open_orders' => 0,
            'max_open_exposure_mg' => 1,
            'allowed_settlement_types' => [],
        ]);
        $this->organizations->status = 'SUSPENDED';
        $this->organizations->licenseExpiresAt = CarbonImmutable::now()->subYear();
        $this->exposures->openOrders = 99;
        $this->exposures->goldMg = 99_000_000;

        $decision = $this->guard()->evaluate($this->intent(fineWeightMg: 1_000_000));

        $this->assertSame('TRADING_NOT_ALLOWED', $decision->reasonCode);
    }

    #[Test]
    public function the_expensive_aggregate_checks_never_run_when_a_cheap_one_fails(): void
    {
        // A per-order breach (check 4) must be reported before the open-order
        // count (check 6) and the exposure query (check 7) are even consulted.
        $this->profile([
            'max_order_mg' => 100,
            'max_open_orders' => 0,
            'max_open_exposure_mg' => 0,
        ]);
        $this->exposures->openOrders = 500;
        $this->exposures->goldMg = 500_000_000;

        $decision = $this->guard()->evaluate($this->intent(fineWeightMg: 1_000_000));

        $this->assertSame(LimitType::PER_ORDER, $decision->limitType);
    }

    #[Test]
    public function the_daily_ceiling_is_reported_before_the_exposure_query(): void
    {
        $this->profile([
            'max_order_mg' => 10_000_000,
            'max_daily_volume_mg' => 1,
            'max_open_orders' => 0,
            'max_open_exposure_mg' => 0,
        ]);
        $this->exposures->openOrders = 500;

        $decision = $this->guard()->evaluate($this->intent(fineWeightMg: 100_000));

        $this->assertSame(LimitType::DAILY_VOLUME, $decision->limitType);
    }

    #[Test]
    public function a_rejection_announces_itself(): void
    {
        Event::fake([LimitExceeded::class]);
        $this->profile(['max_order_mg' => 10]);

        try {
            $this->guard()->assertTradeAllowed($this->intent(fineWeightMg: 100_000));
            $this->fail('expected the guard to reject');
        } catch (LimitExceededException) {
            // expected
        }

        Event::assertDispatched(
            LimitExceeded::class,
            fn (LimitExceeded $e): bool => $e->limitType === LimitType::PER_ORDER->value
                && $e->organizationId === self::ORG,
        );
    }

    #[Test]
    public function a_critical_member_can_trade_nothing(): void
    {
        RiskProfileFactory::new()
            ->level(RiskLevel::CRITICAL)
            ->create(['organization_id' => self::ORG]);

        $decision = $this->guard()->evaluate($this->intent(fineWeightMg: 1));

        $this->assertTrue($decision->isRejected());
    }

    private function guard(): RiskGuardInterface
    {
        return $this->app->make(RiskGuardInterface::class);
    }

    private function counters(): DailyCounters
    {
        return $this->app->make(DailyCounters::class);
    }

    /** @param array<string, mixed> $attributes */
    private function profile(array $attributes = []): RiskProfile
    {
        return RiskProfileFactory::new()->create(array_merge([
            'organization_id' => self::ORG,
            'max_order_mg' => 10_000_000,
            'max_daily_volume_mg' => 50_000_000,
            'max_open_orders' => 50,
            'max_open_exposure_mg' => 50_000_000,
            'max_open_exposure_rial' => 4_000_000_000_000,
            'allowed_settlement_types' => ['T0', 'T1'],
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function userLimit(array $attributes): UserLimit
    {
        return UserLimit::query()->create(array_merge([
            'organization_id' => self::ORG,
            'user_id' => self::USER,
            'is_active' => true,
        ], $attributes));
    }

    private function intent(
        int $fineWeightMg = 100_000,
        string $settlementType = 'T0',
        ?int $counterpartyOrgId = null,
        ?CarbonImmutable $at = null,
    ): TradeIntent {
        return new TradeIntent(
            organizationId: self::ORG,
            userId: self::USER,
            side: TradeSide::BUY,
            fineWeightMg: $fineWeightMg,
            priceRial: 78_480_000,
            settlementType: $settlementType,
            counterpartyOrgId: $counterpartyOrgId,
            intendedAt: $at,
        );
    }
}
