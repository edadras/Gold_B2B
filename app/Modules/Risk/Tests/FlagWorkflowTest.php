<?php

declare(strict_types=1);

namespace App\Modules\Risk\Tests;

use App\Modules\Risk\Application\FlagWorkflow;
use App\Modules\Risk\Domain\FlagSeverity;
use App\Modules\Risk\Domain\FlagStatus;
use App\Modules\Risk\Infrastructure\Models\AmlFlag;
use App\Modules\Shared\Exceptions\InvalidStateTransitionException;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** AmlFlag state machine — docs/11-appendix/02-state-machines.md §2.11. */
final class FlagWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private FlagWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workflow = new FlagWorkflow;
    }

    #[Test]
    public function the_happy_path_runs_open_to_cleared(): void
    {
        $flag = $this->flag();

        $this->workflow->assign($flag, 11, 'assigned to me');
        $this->assertSame(FlagStatus::UNDER_REVIEW, $flag->fresh()->status);
        $this->assertSame(11, $flag->fresh()->assigned_to_user_id);

        $this->workflow->clear($flag, 11, 'legitimate bullion dealer activity');
        $this->assertSame(FlagStatus::CLEARED, $flag->fresh()->status);
    }

    #[Test]
    public function escalation_ends_in_an_action(): void
    {
        $flag = $this->flag();

        $this->workflow->assign($flag, 11, 'assigned');
        $this->workflow->escalate($flag, 12, 'needs senior review');
        $this->workflow->recordAction($flag, 13, 'member restricted', 'RESTRICTED');

        $fresh = $flag->fresh();
        $this->assertSame(FlagStatus::ACTION_TAKEN, $fresh->status);
        $this->assertSame('RESTRICTED', $fresh->action_taken);
        $this->assertTrue($fresh->status->isFinal());
    }

    #[Test]
    public function enhanced_review_is_reachable_from_under_review(): void
    {
        $flag = $this->flag();

        $this->workflow->assign($flag, 11, 'assigned');
        $this->workflow->requireEnhancedReview($flag, 11, 'source of funds requested');

        $this->assertSame(FlagStatus::ENHANCED_REVIEW, $flag->fresh()->status);

        $this->workflow->recordAction($flag, 11, 'no documents supplied', 'SUSPENDED');
        $this->assertSame(FlagStatus::ACTION_TAKEN, $flag->fresh()->status);
    }

    #[Test]
    public function a_false_positive_feeds_rule_tuning(): void
    {
        $flag = $this->flag();

        $this->workflow->assign($flag, 11, 'assigned');
        $this->workflow->markFalsePositive($flag, 11, 'threshold is too low for this member');

        $this->assertSame(FlagStatus::FALSE_POSITIVE, $flag->fresh()->status);
        $this->assertTrue($flag->fresh()->status->isFinal());
    }

    #[Test]
    public function an_open_flag_cannot_jump_straight_to_cleared(): void
    {
        $flag = $this->flag();

        $this->expectException(InvalidStateTransitionException::class);
        $this->workflow->clear($flag, 11, 'nothing to see here');
    }

    #[Test]
    public function a_final_flag_never_changes_again(): void
    {
        $flag = $this->flag(['status' => FlagStatus::ACTION_TAKEN->value]);

        $this->expectException(InvalidStateTransitionException::class);
        $this->workflow->clear($flag, 11, 'reconsidered');
    }

    #[Test]
    public function every_transition_requires_a_note(): void
    {
        $flag = $this->flag();

        $this->expectException(OperationNotPermittedException::class);
        $this->workflow->assign($flag, 11, '   ');
    }

    #[Test]
    public function every_invalid_transition_is_refused(): void
    {
        foreach (FlagStatus::cases() as $from) {
            foreach (FlagStatus::cases() as $to) {
                $flag = $this->flag(['status' => $from->value]);

                if ($from->canTransitionTo($to)) {
                    $this->workflow->transition($flag, $to, 11, 'note');
                    $this->assertSame($to, $flag->fresh()->status, "{$from->value} → {$to->value}");

                    continue;
                }

                try {
                    $this->workflow->transition($flag, $to, 11, 'note');
                    $this->fail("{$from->value} → {$to->value} should have been refused");
                } catch (InvalidStateTransitionException) {
                    // expected
                }
            }
        }
    }

    #[Test]
    public function a_regulatory_report_is_recorded_without_changing_state(): void
    {
        $flag = $this->flag();
        $this->workflow->assign($flag, 11, 'assigned');

        $this->workflow->recordRegulatoryReport($flag, 'FIU-2026-0001');

        $fresh = $flag->fresh();
        $this->assertSame('FIU-2026-0001', $fresh->report_reference);
        $this->assertNotNull($fresh->reported_at);
        $this->assertSame(FlagStatus::UNDER_REVIEW, $fresh->status);
    }

    #[Test]
    public function notes_accumulate_rather_than_overwrite(): void
    {
        $flag = $this->flag();

        $this->workflow->assign($flag, 11, 'first note');
        $this->workflow->escalate($flag, 12, 'second note');

        $notes = (string) $flag->fresh()->resolution_notes;
        $this->assertStringContainsString('first note', $notes);
        $this->assertStringContainsString('second note', $notes);
    }

    /** @param array<string, mixed> $attributes */
    private function flag(array $attributes = []): AmlFlag
    {
        return AmlFlag::query()->create(array_merge([
            'rule_code' => 'PAT-01',
            'organization_id' => 8_801,
            'severity' => FlagSeverity::HIGH->value,
            'status' => FlagStatus::OPEN->value,
            'summary' => 'معامله رفت‌وبرگشتی شناسایی شد',
            'context' => ['reverse_trade_id' => 1],
            'raised_at' => CarbonImmutable::now(),
        ], $attributes));
    }
}
