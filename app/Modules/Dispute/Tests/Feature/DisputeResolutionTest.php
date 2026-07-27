<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Tests\Feature;

use App\Modules\Dispute\Application\DeadlineProcessor;
use App\Modules\Dispute\Application\DisputeService;
use App\Modules\Dispute\Application\NegotiationService;
use App\Modules\Dispute\Application\ResolutionService;
use App\Modules\Dispute\Contracts\OpenDisputeCommand;
use App\Modules\Dispute\Domain\DisputeDecision;
use App\Modules\Dispute\Domain\DisputeStatus;
use App\Modules\Dispute\Domain\DisputeType;
use App\Modules\Dispute\Events\DisputeReputationAssessed;
use App\Modules\Dispute\Events\DisputeResolved;
use App\Modules\Dispute\Infrastructure\Models\DisputeModel;
use App\Modules\Dispute\Tests\DisputeTestCase;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

/**
 * §13.6 deadlines and §13.7 verdicts.
 */
final class DisputeResolutionTest extends DisputeTestCase
{
    private const CLAIMANT = 184;

    private const RESPONDENT = 209;

    private DisputeService $disputes;

    private ResolutionService $resolutions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disputes = $this->app->make(DisputeService::class);
        $this->resolutions = $this->app->make(ResolutionService::class);
    }

    #[Test]
    public function a_missed_reply_deadline_escalates_to_mediation_automatically(): void
    {
        $dispute = $this->openPurityDispute();

        self::assertSame(DisputeStatus::AWAITING_REPLY->value, $dispute->status);

        // 25 hours later, with nothing heard from the respondent.
        $result = $this->app->make(DeadlineProcessor::class)
            ->sweep(Carbon::now()->addHours(25));

        self::assertSame(1, $result->noReplyEscalated);
        self::assertFalse($result->hasFailures());

        $dispute->refresh();

        self::assertSame(DisputeStatus::UNDER_MEDIATION->value, $dispute->status);

        // §2.14 rule 4: the machine did it, and the timeline says so.
        $row = DB::table('dispute_timeline')
            ->where('dispute_id', $dispute->id)
            ->where('action', 'NO_REPLY_TIMEOUT')
            ->first();

        self::assertNotNull($row);
        self::assertSame('SYSTEM', $row->actor_type);
        self::assertNull($row->actor_user_id);
    }

    #[Test]
    public function a_missed_negotiation_deadline_also_escalates(): void
    {
        $dispute = $this->openPurityDispute();

        $this->disputes->disputeClaim($dispute, 77, 'گواهی ما ۹۹۵ است');
        self::assertSame(DisputeStatus::NEGOTIATION->value, $dispute->status);

        $result = $this->app->make(DeadlineProcessor::class)
            ->sweep(Carbon::now()->addHours(49));

        self::assertSame(1, $result->negotiationEscalated);
        self::assertSame(
            DisputeStatus::UNDER_MEDIATION->value,
            (string) DisputeModel::query()->whereKey($dispute->id)->value('status'),
        );
    }

    #[Test]
    public function a_case_answered_in_time_is_not_swept_up(): void
    {
        $dispute = $this->openPurityDispute();

        $this->disputes->acceptClaim($dispute, 77);

        $result = $this->app->make(DeadlineProcessor::class)
            ->sweep(Carbon::now()->addHours(72));

        self::assertSame(0, $result->total());
        self::assertSame(
            DisputeStatus::ACCEPTED_BY_RESPONDENT->value,
            (string) DisputeModel::query()->whereKey($dispute->id)->value('status'),
        );
    }

    #[Test]
    public function resolving_releases_the_hold(): void
    {
        $dispute = $this->openPurityDispute();

        self::assertSame(1, $this->holds->countOf('holdGold'));
        self::assertSame(0, $this->holds->countOf('releaseGoldHold'));

        $this->disputes->acceptClaim($dispute, 77);

        $this->resolutions->decide(
            dispute: $dispute,
            decision: DisputeDecision::CLAIM_UPHELD_FULL,
            awardedGoldMg: 5_025,
            rationale: 'پاسخ‌دهنده ادعا را پذیرفت',
            decidedByUserId: 3,
        );

        // Exactly what was locked comes back off hold, no more and no less.
        self::assertSame(1, $this->holds->countOf('releaseGoldHold'));
        self::assertSame(5_025, $this->holds->callsTo('releaseGoldHold')[0]['amount']);
        self::assertSame(394_362_000, $this->holds->callsTo('releaseRialHold')[0]['amount']);

        $dispute->refresh();

        self::assertTrue($dispute->hold_released);
        self::assertSame(DisputeStatus::EXECUTED->value, $dispute->status);
        self::assertNotNull($dispute->executed_at);
    }

    #[Test]
    public function the_hold_is_released_before_the_award_is_transferred(): void
    {
        $dispute = $this->openPurityDispute();
        $this->disputes->acceptClaim($dispute, 77);

        $this->resolutions->decide(
            dispute: $dispute,
            decision: DisputeDecision::CLAIM_UPHELD_FULL,
            awardedGoldMg: 5_025,
            decidedByUserId: 3,
        );

        $operations = array_column($this->holds->calls, 'op');

        $releaseAt = array_search('releaseGoldHold', $operations, true);
        $transferAt = array_search('transferGold', $operations, true);

        self::assertIsInt($releaseAt);
        self::assertIsInt($transferAt);
        self::assertLessThan(
            $transferAt,
            $releaseAt,
            'the hold must come off before the award is paid out of the same balance',
        );
    }

    #[Test]
    public function an_upheld_claim_moves_the_award_from_respondent_to_claimant(): void
    {
        $dispute = $this->openPurityDispute();
        $this->disputes->acceptClaim($dispute, 77);

        $this->resolutions->decide(
            dispute: $dispute,
            decision: DisputeDecision::CLAIM_UPHELD_FULL,
            awardedGoldMg: 5_025,
            awardedRial: 0,
            decidedByUserId: 3,
        );

        $transfer = $this->holds->callsTo('transferGold')[0];

        self::assertSame(self::RESPONDENT, $transfer['org'], 'paid by the respondent');
        self::assertSame(self::CLAIMANT, $transfer['to'], 'received by the claimant');
        self::assertSame(5_025, $transfer['amount']);
    }

    #[Test]
    public function a_rejected_claim_releases_the_hold_and_moves_nothing(): void
    {
        $dispute = $this->openPurityDispute();
        $this->disputes->escalateToMediation($dispute, 3);

        $this->resolutions->decide(
            dispute: $dispute,
            decision: DisputeDecision::CLAIM_REJECTED,
            rationale: 'گواهی مستقل عیار ۹۹۵ را تأیید کرد',
            decidedByUserId: 3,
        );

        self::assertSame(1, $this->holds->countOf('releaseGoldHold'));
        self::assertSame(0, $this->holds->countOf('transferGold'));
        self::assertSame(0, $this->holds->countOf('transferRial'));
    }

    #[Test]
    public function a_verdict_that_carries_no_award_refuses_to_be_given_one(): void
    {
        $dispute = $this->openPurityDispute();
        $this->disputes->escalateToMediation($dispute, 3);

        $this->expectException(InvalidArgumentException::class);

        $this->resolutions->decide(
            dispute: $dispute,
            decision: DisputeDecision::CLAIM_REJECTED,
            awardedRial: 100_000_000,
            decidedByUserId: 3,
        );
    }

    #[Test]
    public function reputation_is_announced_not_applied(): void
    {
        Event::fake([DisputeResolved::class, DisputeReputationAssessed::class]);

        $dispute = $this->openPurityDispute();
        $this->disputes->escalateToMediation($dispute, 3);

        $this->resolutions->decide(
            dispute: $dispute,
            decision: DisputeDecision::CLAIM_UPHELD_PARTIAL,
            awardedGoldMg: 2_500,
            decidedByUserId: 3,
        );

        Event::assertDispatched(DisputeResolved::class);

        Event::assertDispatched(
            DisputeReputationAssessed::class,
            static function (DisputeReputationAssessed $event): bool {
                return $event->losingParty === 'RESPONDENT'
                    && $event->losingOrgId === self::RESPONDENT
                    && $event->isFrivolousClaim === false
                    && $event->isPlatformFault === false;
            },
        );
    }

    #[Test]
    public function a_system_fault_penalises_neither_party(): void
    {
        Event::fake([DisputeReputationAssessed::class]);

        $dispute = $this->openPurityDispute();
        $this->disputes->escalateToMediation($dispute, 3);

        $this->resolutions->decide(
            dispute: $dispute,
            decision: DisputeDecision::SYSTEM_FAULT,
            awardedRial: 394_362_000,
            decidedByUserId: 3,
        );

        Event::assertDispatched(
            DisputeReputationAssessed::class,
            static fn (DisputeReputationAssessed $e): bool => $e->losingParty === null
                && $e->losingOrgId === null
                && $e->isPlatformFault === true,
        );
    }

    #[Test]
    public function accepting_a_settlement_proposal_resolves_the_case_without_an_operator(): void
    {
        $dispute = $this->openPurityDispute();
        $this->disputes->disputeClaim($dispute, 77, 'حاضریم مذاکره کنیم');

        $negotiation = $this->app->make(NegotiationService::class);

        $proposal = $negotiation->proposeSettlement(
            dispute: $dispute,
            organizationId: self::RESPONDENT,
            userId: 77,
            offeredGoldMg: 0,
            offeredRial: 300_000_000,
            body: 'حاضریم ۳۰۰ میلیون بپردازیم',
        );

        $negotiation->acceptProposal($dispute, (int) $proposal->id, self::CLAIMANT, 41);

        $dispute->refresh();

        self::assertSame(DisputeDecision::SETTLED_BY_AGREEMENT->value, $dispute->decision);
        self::assertSame(DisputeStatus::EXECUTED->value, $dispute->status);
        self::assertSame(300_000_000, $dispute->awarded_rial);
        self::assertNull($dispute->mediator_user_id, 'no operator was involved');

        $transfer = $this->holds->callsTo('transferRial')[0];
        self::assertSame(self::RESPONDENT, $transfer['org']);
        self::assertSame(self::CLAIMANT, $transfer['to']);
        self::assertSame(300_000_000, $transfer['amount']);
    }

    #[Test]
    public function a_party_cannot_accept_its_own_proposal(): void
    {
        $dispute = $this->openPurityDispute();
        $this->disputes->disputeClaim($dispute, 77, 'مذاکره');

        $negotiation = $this->app->make(NegotiationService::class);

        $proposal = $negotiation->proposeSettlement(
            $dispute,
            self::RESPONDENT,
            77,
            0,
            300_000_000,
        );

        $this->expectException(OperationNotPermittedException::class);

        $negotiation->acceptProposal($dispute, (int) $proposal->id, self::RESPONDENT, 77);
    }

    #[Test]
    public function withdrawing_releases_the_hold_and_changes_nothing_else(): void
    {
        $dispute = $this->openPurityDispute();

        $this->disputes->withdraw($dispute, 41, 'اشتباه از ما بود');

        self::assertSame(DisputeStatus::WITHDRAWN->value, $dispute->status);

        $this->resolutions->executeWithdrawal($dispute);

        self::assertSame(1, $this->holds->countOf('releaseGoldHold'));
        self::assertSame(0, $this->holds->countOf('transferGold'));
        self::assertSame(0, $this->holds->countOf('transferRial'));
    }

    #[Test]
    public function executing_twice_does_not_release_or_pay_twice(): void
    {
        $dispute = $this->openPurityDispute();
        $this->disputes->acceptClaim($dispute, 77);

        $this->resolutions->decide(
            dispute: $dispute,
            decision: DisputeDecision::CLAIM_UPHELD_FULL,
            awardedGoldMg: 5_025,
            decidedByUserId: 3,
        );

        $this->resolutions->execute($dispute);
        $this->resolutions->execute($dispute);

        self::assertSame(1, $this->holds->countOf('releaseGoldHold'));
        self::assertSame(1, $this->holds->countOf('transferGold'));
    }

    private function openPurityDispute(): DisputeModel
    {
        $this->documentedTrade();

        return $this->disputes->open(new OpenDisputeCommand(
            type: DisputeType::PURITY_MISMATCH,
            claimantOrgId: self::CLAIMANT,
            openedByUserId: 41,
            claimDescription: 'عیار تحویلی ۹۸۵ است نه ۹۹۵',
            tradeId: 88231,
            actualPurityX10k: 9_850,
        ));
    }
}
