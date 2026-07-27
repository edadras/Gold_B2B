<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Tests\Feature;

use App\Modules\Dispute\Application\DisputeService;
use App\Modules\Dispute\Application\NegotiationService;
use App\Modules\Dispute\Contracts\OpenDisputeCommand;
use App\Modules\Dispute\Domain\DisputeDecision;
use App\Modules\Dispute\Domain\DisputeStatus;
use App\Modules\Dispute\Domain\DisputeType;
use App\Modules\Dispute\Domain\MessageType;
use App\Modules\Dispute\Infrastructure\Models\DisputeModel;
use App\Modules\Dispute\Tests\DisputeTestCase;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

/**
 * The negotiation room of §2.12 — the part of a dispute where the two parties
 * talk and try to settle it themselves before a mediator has to.
 *
 * NegotiationService was written and tested; the HTTP layer that reaches it was
 * not. PostDisputeMessageRequest and ProposeSettlementRequest existed as
 * validated FormRequests with no route pointing at them, so a claimant could
 * open a case and then never speak in it again — `/reply` is specifically the
 * respondent's rejection. Negotiation with one party unable to talk is not
 * negotiation, and the module's own suite could not see it because it drove the
 * service directly.
 */
final class NegotiationRoomTest extends DisputeTestCase
{
    private const CLAIMANT = 184;

    private const RESPONDENT = 209;

    private DisputeService $disputes;

    private NegotiationService $negotiation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disputes = $this->app->make(DisputeService::class);
        $this->negotiation = $this->app->make(NegotiationService::class);
    }

    #[Test]
    public function the_claimant_can_speak_before_the_respondent_has_replied(): void
    {
        // The gap this closes: on a fresh case only /reply existed, and /reply
        // is the respondent's. The claimant had no way to add a word to their
        // own case until the other side chose to answer.
        $dispute = $this->openFreshCase();

        $message = $this->negotiation->postMessage($dispute, self::CLAIMANT, 41, 'گزارش ری‌گیری پیوست شد.');

        $this->assertSame(MessageType::MESSAGE->value, $message->message_type);
        $this->assertSame(self::CLAIMANT, (int) $message->sender_org_id);
    }

    #[Test]
    public function both_parties_can_speak(): void
    {
        $dispute = $this->openCase();

        $fromClaimant = $this->negotiation->postMessage($dispute, self::CLAIMANT, 41, 'گزارش ری‌گیری پیوست است.');
        $fromRespondent = $this->negotiation->postMessage($dispute, self::RESPONDENT, 77, 'شمش را دوباره می‌فرستیم.');

        $this->assertSame(MessageType::MESSAGE->value, $fromClaimant->message_type);
        $this->assertSame(self::CLAIMANT, (int) $fromClaimant->sender_org_id);
        $this->assertSame(self::RESPONDENT, (int) $fromRespondent->sender_org_id);
    }

    #[Test]
    public function a_stranger_cannot_speak_in_someone_elses_case(): void
    {
        $dispute = $this->openCase();

        $this->expectException(OperationNotPermittedException::class);

        $this->negotiation->postMessage($dispute, 999, 5, 'من هم نظری دارم');
    }

    #[Test]
    public function an_empty_message_is_not_a_message(): void
    {
        $dispute = $this->openCase();

        $this->expectException(InvalidArgumentException::class);

        $this->negotiation->postMessage($dispute, self::CLAIMANT, 41, '   ');
    }

    #[Test]
    public function accepting_a_proposal_settles_the_case_by_agreement(): void
    {
        $dispute = $this->openCase();

        // The respondent offers 2 g of fine gold to close it.
        $proposal = $this->negotiation->proposeSettlement(
            $dispute,
            self::RESPONDENT,
            77,
            offeredGoldMg: 2_000,
            offeredRial: 0,
            body: 'جبران کسری عیار',
        );

        $settled = $this->negotiation->acceptProposal($dispute, (int) $proposal->id, self::CLAIMANT, 41);

        $this->assertSame(DisputeDecision::SETTLED_BY_AGREEMENT->value, $settled->decision);

        // EXECUTED rather than RESOLVED: an agreed settlement has nothing left
        // to decide, so ResolutionService carries out the award in the same
        // breath instead of leaving it queued for an operator.
        $this->assertSame(DisputeStatus::EXECUTED->value, $settled->status);

        // The respondent offered, so the award runs toward the claimant. The
        // sign is the service's to work out — neither client sends one.
        $this->assertSame(2_000, (int) $settled->awarded_gold_mg);

        // And the gold genuinely moved: the hold was released and 2 g
        // transferred, not merely recorded as a verdict.
        $this->assertSame(1, $this->holds->countOf('transferGold'));
        $this->assertSame(1, $this->holds->countOf('releaseGoldHold'));
    }

    #[Test]
    public function a_party_cannot_accept_its_own_offer(): void
    {
        $dispute = $this->openCase();

        $proposal = $this->negotiation->proposeSettlement($dispute, self::RESPONDENT, 77, 2_000, 0);

        $this->expectException(OperationNotPermittedException::class);

        $this->negotiation->acceptProposal($dispute, (int) $proposal->id, self::RESPONDENT, 77);
    }

    #[Test]
    public function a_proposal_cannot_be_answered_twice(): void
    {
        $dispute = $this->openCase();

        $proposal = $this->negotiation->proposeSettlement($dispute, self::RESPONDENT, 77, 2_000, 0);

        $this->negotiation->rejectProposal($dispute, (int) $proposal->id, self::CLAIMANT, 41, 'کافی نیست');

        // Otherwise a case could be settled from a message the other side has
        // already turned down.
        $this->expectException(OperationNotPermittedException::class);

        $this->negotiation->acceptProposal($dispute, (int) $proposal->id, self::CLAIMANT, 41);
    }

    #[Test]
    public function an_offer_of_nothing_is_not_an_offer(): void
    {
        $dispute = $this->openCase();

        $this->expectException(InvalidArgumentException::class);

        $this->negotiation->proposeSettlement($dispute, self::RESPONDENT, 77, 0, 0);
    }

    #[Test]
    public function rejecting_leaves_the_case_open_for_another_round(): void
    {
        $dispute = $this->openCase();

        $first = $this->negotiation->proposeSettlement($dispute, self::RESPONDENT, 77, 1_000, 0);
        $this->negotiation->rejectProposal($dispute, (int) $first->id, self::CLAIMANT, 41, 'کم است');

        $dispute->refresh();

        $this->assertNull($dispute->decision, 'A rejected offer must not resolve the case');

        // And the room still works: a better offer can follow.
        $second = $this->negotiation->proposeSettlement($dispute, self::RESPONDENT, 77, 2_000, 0);
        $settled = $this->negotiation->acceptProposal($dispute, (int) $second->id, self::CLAIMANT, 41);

        $this->assertSame(DisputeDecision::SETTLED_BY_AGREEMENT->value, $settled->decision);
        $this->assertSame(2_000, (int) $settled->awarded_gold_mg);
    }

    /**
     * A case with the room already open.
     *
     * A freshly opened case is AWAITING_REPLY, and settling from there is not a
     * legal transition — the respondent has to answer before the two sides can
     * bargain. `disputeClaim()` is that answer: it rejects the claim and moves
     * the case to NEGOTIATION.
     */
    private function openCase(): DisputeModel
    {
        $dispute = $this->openFreshCase();

        return $this->disputes->disputeClaim($dispute, 77, 'عیار اعلامی را قبول نداریم');
    }

    /** Still AWAITING_REPLY — nobody has answered yet. */
    private function openFreshCase(): DisputeModel
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
