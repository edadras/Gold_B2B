<?php

declare(strict_types=1);

namespace App\Modules\Risk\Tests;

use App\Modules\Risk\Aml\AmlContext;
use App\Modules\Risk\Aml\AmlEventType;
use App\Modules\Risk\Contracts\AmlEvaluatorInterface;
use App\Modules\Risk\Database\Seeders\AmlRulesSeeder;
use App\Modules\Risk\Domain\FlagSeverity;
use App\Modules\Risk\Domain\FlagStatus;
use App\Modules\Risk\Domain\RuleOutcome;
use App\Modules\Risk\Events\AmlFlagRaised;
use App\Modules\Risk\Exceptions\AmlBlockedException;
use App\Modules\Risk\Infrastructure\Models\AmlFlag;
use App\Modules\Risk\Infrastructure\Models\AmlRuleModel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** docs/03-domain/12-aml-compliance.md §12.2 wired end to end. */
final class AmlRuleEngineTest extends TestCase
{
    use RefreshDatabase;

    private const ORG = 7_701;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-01-05 12:00:00');
        $this->seed(AmlRulesSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function the_catalogue_is_seeded_with_its_documented_defaults(): void
    {
        $this->assertDatabaseHas('aml_rules', ['code' => 'VOL-01', 'severity' => 'MEDIUM', 'action' => 'FLAG']);
        $this->assertDatabaseHas('aml_rules', ['code' => 'PAT-02', 'severity' => 'CRITICAL', 'action' => 'BLOCK']);
        $this->assertDatabaseHas('aml_rules', ['code' => 'CPT-04', 'severity' => 'CRITICAL', 'action' => 'BLOCK']);
        $this->assertDatabaseHas('aml_rules', ['code' => 'SAN-01', 'is_active' => 0]);

        $vol01 = AmlRuleModel::query()->where('code', 'VOL-01')->firstOrFail();
        $this->assertSame(10_000_000, $vol01->parameters['threshold_mg']);

        $str01 = AmlRuleModel::query()->where('code', 'STR-01')->firstOrFail();
        $this->assertSame(5, $str01->parameters['min_count']);
    }

    #[Test]
    public function seeding_twice_does_not_duplicate_the_catalogue(): void
    {
        $before = AmlRuleModel::query()->count();
        $this->seed(AmlRulesSeeder::class);

        $this->assertSame($before, AmlRuleModel::query()->count());
    }

    #[Test]
    public function an_ordinary_trade_passes_with_no_flags(): void
    {
        $evaluation = $this->engine()->evaluate($this->context());

        $this->assertSame(RuleOutcome::PASS, $evaluation->outcome);
        $this->assertFalse($evaluation->raisedFlags());
        $this->assertDatabaseCount('aml_flags', 0);
    }

    #[Test]
    public function a_self_trade_is_blocked_and_flagged(): void
    {
        Event::fake([AmlFlagRaised::class]);

        $evaluation = $this->engine()->evaluate($this->context(buyer: self::ORG, seller: self::ORG));

        $this->assertTrue($evaluation->isBlocked());
        $this->assertSame(['CPT-04'], $evaluation->blockingRuleCodes);
        $this->assertContains('CPT-04', $evaluation->matchedRuleCodes());

        $flag = AmlFlag::query()->firstOrFail();
        $this->assertSame('CPT-04', $flag->rule_code);
        $this->assertSame(FlagSeverity::CRITICAL, $flag->severity);
        $this->assertSame(FlagStatus::OPEN, $flag->status);
        $this->assertSame(self::ORG, $flag->organization_id);

        Event::assertDispatched(
            AmlFlagRaised::class,
            fn (AmlFlagRaised $e): bool => $e->ruleCode === 'CPT-04' && $e->organizationId === self::ORG,
        );
    }

    #[Test]
    public function assert_allowed_throws_on_a_block(): void
    {
        $this->expectException(AmlBlockedException::class);

        $this->engine()->assertAllowed($this->context(buyer: self::ORG, seller: self::ORG));
    }

    #[Test]
    public function a_large_trade_raises_a_flag_without_blocking(): void
    {
        $evaluation = $this->engine()->evaluate($this->context(fineWeightMg: 25_000_000));

        $this->assertSame(RuleOutcome::FLAG, $evaluation->outcome);
        $this->assertFalse($evaluation->isBlocked());
        $this->assertDatabaseHas('aml_flags', ['rule_code' => 'VOL-01', 'organization_id' => self::ORG]);
    }

    #[Test]
    public function an_inactive_rule_is_never_evaluated(): void
    {
        AmlRuleModel::query()->where('code', 'VOL-01')->update(['is_active' => false]);

        $evaluation = $this->engine()->evaluate($this->context(fineWeightMg: 25_000_000));

        $this->assertArrayNotHasKey('VOL-01', $evaluation->results);
        $this->assertDatabaseCount('aml_flags', 0);
    }

    #[Test]
    public function a_rule_that_does_not_apply_to_the_event_is_skipped(): void
    {
        // BEH-03 only lists WITHDRAWAL in applies_to.
        $evaluation = $this->engine()->evaluate($this->context());

        $this->assertArrayNotHasKey('BEH-03', $evaluation->results);
    }

    #[Test]
    public function a_block_rule_degrades_to_a_flag_once_the_trade_has_happened(): void
    {
        // There is nothing left to stop after execution, so CPT-04 flags instead.
        $evaluation = $this->engine()->evaluate($this->context(
            eventType: AmlEventType::TRADE_EXECUTED,
            buyer: self::ORG,
            seller: self::ORG,
        ));

        $this->assertSame(RuleOutcome::FLAG, $evaluation->outcome);
        $this->assertFalse($evaluation->isBlocked());
        $this->assertDatabaseHas('aml_flags', ['rule_code' => 'CPT-04']);
    }

    private function engine(): AmlEvaluatorInterface
    {
        return $this->app->make(AmlEvaluatorInterface::class);
    }

    private function context(
        AmlEventType $eventType = AmlEventType::TRADE_INTENT,
        ?int $buyer = null,
        ?int $seller = null,
        int $fineWeightMg = 100_000,
    ): AmlContext {
        return new AmlContext(
            eventType: $eventType,
            organizationId: self::ORG,
            userId: 42,
            buyerOrganizationId: $buyer ?? self::ORG,
            sellerOrganizationId: $seller ?? 7_702,
            fineWeightMg: $fineWeightMg,
            pricePerGramRial: 78_480_000,
            occurredAt: CarbonImmutable::now(),
            subjectType: 'trade',
            subjectId: 555,
        );
    }
}
