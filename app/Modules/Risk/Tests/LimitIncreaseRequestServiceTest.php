<?php

declare(strict_types=1);

namespace App\Modules\Risk\Tests;

use App\Modules\Risk\Application\DailyCounters;
use App\Modules\Risk\Application\LimitIncreaseRequestService;
use App\Modules\Risk\Contracts\MemberActivityReaderInterface;
use App\Modules\Risk\Contracts\MemberActivityStats;
use App\Modules\Risk\Database\Factories\RiskProfileFactory;
use App\Modules\Risk\Domain\LimitIncreaseStatus;
use App\Modules\Risk\Domain\LimitType;
use App\Modules\Risk\Infrastructure\Models\RiskProfile;
use App\Modules\Shared\Exceptions\InvalidStateTransitionException;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Rial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** §11.5 counters and the §11.8 limit-increase workflow. */
final class LimitIncreaseRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    private const ORG = 6_601;

    private const USER = 3_301;

    protected function tearDown(): void
    {
        $this->app->make(DailyCounters::class)->reset(self::ORG, self::USER);
        parent::tearDown();
    }

    #[Test]
    public function daily_counters_accumulate_and_key_on_the_market_date(): void
    {
        $counters = $this->app->make(DailyCounters::class);
        $counters->reset(self::ORG, self::USER);

        $counters->increment(self::ORG, FineWeight::fromMilligrams(250_000), Rial::fromRial(19_521_900_000), self::USER);
        $counters->increment(self::ORG, FineWeight::fromMilligrams(100_000), Rial::fromRial(7_848_000_000), self::USER);

        $this->assertSame(350_000, $counters->dailyVolumeMg(self::ORG));
        $this->assertSame(27_369_900_000, $counters->dailyValueRial(self::ORG));
        $this->assertSame(2, $counters->tradeCount(self::ORG));
        $this->assertSame(350_000, $counters->userDailyVolumeMg(self::USER));

        // The date is baked into the key, so no midnight reset job is needed.
        $this->assertStringContainsString(
            now(config('goldb2b.market.timezone'))->toDateString(),
            $counters->key(self::ORG),
        );
    }

    #[Test]
    public function the_counter_is_mirrored_into_the_history_table(): void
    {
        $counters = $this->app->make(DailyCounters::class);
        $counters->reset(self::ORG, self::USER);

        $counters->increment(self::ORG, FineWeight::fromMilligrams(250_000), Rial::fromRial(1_000));

        // QUEUE_CONNECTION is sync under test, so the job has already run.
        $this->assertDatabaseHas('daily_counters', [
            'organization_id' => self::ORG,
            'volume_mg' => 250_000,
            'trade_count' => 1,
        ]);
    }

    #[Test]
    public function a_member_who_fails_the_prerequisites_is_rejected_automatically(): void
    {
        $this->profile(['credit_score' => 500]);
        $this->stats(new MemberActivityStats(daysActive: 10, settledTradeCount: 2));

        $request = $this->service()->request(self::ORG, self::USER, LimitType::PER_ORDER, 5_000_000);

        $this->assertSame(LimitIncreaseStatus::AUTO_REJECTED, $request->status);
        $this->assertSame([
            'MIN_90_DAYS_ACTIVE',
            'MIN_50_SETTLED_TRADES',
            'ON_TIME_RATE_ABOVE_98',
            'KYC_COMPLETE_AND_CURRENT',
            'CREDIT_SCORE_ABOVE_700',
        ], $request->failed_prerequisites);

        // Reported in the documented order, and only ever a subset of it.
        $this->assertSame(
            array_values(array_intersect(
                LimitIncreaseRequestService::PREREQUISITES,
                $request->failed_prerequisites,
            )),
            $request->failed_prerequisites,
        );
    }

    #[Test]
    public function a_qualifying_member_goes_to_human_review(): void
    {
        $this->profile(['credit_score' => 750]);
        $this->stats($this->qualifyingStats());

        $request = $this->service()->request(self::ORG, self::USER, LimitType::PER_ORDER, 5_000_000);

        $this->assertSame(LimitIncreaseStatus::PENDING_REVIEW, $request->status);
        $this->assertSame([], $request->failed_prerequisites);
        $this->assertSame(750, $request->prerequisite_snapshot['credit_score']);
    }

    #[Test]
    public function each_prerequisite_can_fail_on_its_own(): void
    {
        $profile = $this->profile(['credit_score' => 750]);
        $service = $this->service();

        $cases = [
            'MIN_90_DAYS_ACTIVE' => ['daysActive' => 10],
            'MIN_50_SETTLED_TRADES' => ['settledTradeCount' => 4],
            'ON_TIME_RATE_ABOVE_98' => ['settlementsOnTime' => 90, 'settlementsTotal' => 100],
            'NO_DEFAULT_IN_180_DAYS' => ['defaultsLast180Days' => 1],
            'KYC_COMPLETE_AND_CURRENT' => ['kycVerifiedItems' => 3],
        ];

        foreach ($cases as $expected => $overrides) {
            $stats = $this->qualifyingStats($overrides);

            $this->assertSame([$expected], $service->failedPrerequisites($stats, $profile), $expected);
        }

        $lowScore = $this->profile(['organization_id' => self::ORG + 1, 'credit_score' => 700]);
        $this->assertSame(
            ['CREDIT_SCORE_ABOVE_700'],
            $service->failedPrerequisites($this->qualifyingStats(), $lowScore),
            'the bar is strictly above 700',
        );
    }

    #[Test]
    public function approval_raises_the_ceiling_on_the_profile(): void
    {
        $this->profile(['credit_score' => 750, 'max_order_mg' => 2_000_000]);
        $this->stats($this->qualifyingStats());

        $service = $this->service();
        $request = $service->request(self::ORG, self::USER, LimitType::PER_ORDER, 5_000_000);
        $service->approve($request, 77, 'reviewed, documents in order');

        $this->assertSame(LimitIncreaseStatus::APPROVED, $request->fresh()->status);
        $this->assertSame(5_000_000, RiskProfile::query()
            ->where('organization_id', self::ORG)
            ->value('max_order_mg'));
        $this->assertNotNull($request->fresh()->next_review_at);
    }

    #[Test]
    public function conditional_approval_records_the_collateral_requirement(): void
    {
        $this->profile(['credit_score' => 750]);
        $this->stats($this->qualifyingStats());

        $service = $this->service();
        $request = $service->request(self::ORG, self::USER, LimitType::DAILY_VOLUME, 50_000_000);
        $service->approveWithCollateral($request, 77, 'needs security first', 900_000_000_000);

        $fresh = $request->fresh();
        $this->assertSame(LimitIncreaseStatus::APPROVED_WITH_COLLATERAL, $fresh->status);
        $this->assertSame(900_000_000_000, $fresh->required_collateral_rial);
    }

    #[Test]
    public function an_auto_rejected_request_cannot_be_approved(): void
    {
        $this->profile(['credit_score' => 100]);
        $this->stats(new MemberActivityStats);

        $service = $this->service();
        $request = $service->request(self::ORG, self::USER, LimitType::PER_ORDER, 5_000_000);

        $this->expectException(InvalidStateTransitionException::class);
        $service->approve($request, 77, 'looks fine to me');
    }

    private function service(): LimitIncreaseRequestService
    {
        return $this->app->make(LimitIncreaseRequestService::class);
    }

    private function stats(MemberActivityStats $stats): void
    {
        $this->app->instance(
            MemberActivityReaderInterface::class,
            new class($stats) implements MemberActivityReaderInterface
            {
                public function __construct(private readonly MemberActivityStats $stats) {}

                public function statsFor(int $organizationId): MemberActivityStats
                {
                    return $this->stats;
                }
            }
        );
    }

    /** @param array<string, int> $overrides */
    private function qualifyingStats(array $overrides = []): MemberActivityStats
    {
        return new MemberActivityStats(...array_merge([
            'settlementsOnTime' => 100,
            'settlementsTotal' => 100,
            'monthsActive' => 12,
            'totalVolumeMg' => 100_000_000,
            'disputesLost' => 0,
            'totalTrades' => 200,
            'kycVerifiedItems' => 8,
            'kycTotalItems' => 8,
            'distinctCounterparties' => 10,
            'activeAmlFlags' => 0,
            'defaultsLast180Days' => 0,
            'settledTradeCount' => 120,
            'daysActive' => 365,
            'kycCurrent' => true,
        ], $overrides));
    }

    /** @param array<string, mixed> $attributes */
    private function profile(array $attributes = []): RiskProfile
    {
        return RiskProfileFactory::new()->create(array_merge([
            'organization_id' => self::ORG,
        ], $attributes));
    }
}
