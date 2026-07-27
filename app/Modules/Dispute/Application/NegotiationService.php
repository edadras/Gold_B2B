<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Application;

use App\Modules\Dispute\Domain\ActorType;
use App\Modules\Dispute\Domain\DisputeDecision;
use App\Modules\Dispute\Domain\DisputeStatus;
use App\Modules\Dispute\Domain\MessageType;
use App\Modules\Dispute\Infrastructure\Models\DisputeMessageModel;
use App\Modules\Dispute\Infrastructure\Models\DisputeModel;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The recorded negotiation room of §13.6 مرحله ۲.
 *
 * Every message is kept and is visible to an operator. A settlement proposal
 * carries its numbers as columns rather than as prose, because accepting one
 * produces a binding verdict with no operator in the loop — «پذیرش پیشنهاد ►
 * رأی توافقی، بدون نیاز به اپراتور» — and a verdict cannot be derived from
 * free text.
 *
 * The amounts on a proposal are signed and expressed FROM THE SENDER'S
 * PERSPECTIVE: a positive figure is what the sender offers to hand over.
 * accept() converts that into the case's own frame (positive = claimant
 * receives) so ResolutionService never has to know who proposed what.
 */
final readonly class NegotiationService
{
    public function __construct(
        private DisputeStateMachine $stateMachine,
        private ResolutionService $resolutions,
    ) {}

    public function postMessage(
        DisputeModel $dispute,
        int $organizationId,
        int $userId,
        string $body,
    ): DisputeMessageModel {
        $this->assertCanSpeak($dispute, $organizationId);

        if (trim($body) === '') {
            throw new InvalidArgumentException('A message cannot be empty');
        }

        return $this->write($dispute, $organizationId, $userId, MessageType::MESSAGE, $body);
    }

    /**
     * Offer a settlement.
     *
     * @param  int  $offeredGoldMg  fine milligrams the sender offers to transfer
     * @param  int  $offeredRial  rial the sender offers to transfer
     */
    public function proposeSettlement(
        DisputeModel $dispute,
        int $organizationId,
        int $userId,
        int $offeredGoldMg,
        int $offeredRial,
        string $body = '',
    ): DisputeMessageModel {
        $this->assertCanSpeak($dispute, $organizationId);

        if ($offeredGoldMg < 0 || $offeredRial < 0) {
            throw new InvalidArgumentException('A proposal offers a non-negative amount');
        }

        if ($offeredGoldMg === 0 && $offeredRial === 0) {
            throw new InvalidArgumentException('A proposal must offer something');
        }

        return $this->write(
            $dispute,
            $organizationId,
            $userId,
            MessageType::SETTLEMENT_PROPOSAL,
            $body !== '' ? $body : 'پیشنهاد تسویه',
            $offeredGoldMg,
            $offeredRial,
        );
    }

    /**
     * Accept the other side's proposal — a verdict of SETTLED_BY_AGREEMENT.
     *
     * The proposer cannot accept their own offer, and the accepted proposal is
     * marked so a case cannot be settled twice from the same message.
     */
    public function acceptProposal(
        DisputeModel $dispute,
        int $proposalId,
        int $organizationId,
        int $userId,
    ): DisputeModel {
        $this->assertCanSpeak($dispute, $organizationId);

        /** @var ?DisputeMessageModel $proposal */
        $proposal = DisputeMessageModel::query()
            ->where('dispute_id', $dispute->id)
            ->whereKey($proposalId)
            ->first();

        if ($proposal === null || $proposal->message_type !== MessageType::SETTLEMENT_PROPOSAL->value) {
            throw new InvalidArgumentException('No such settlement proposal on this case');
        }

        if ($proposal->sender_org_id === $organizationId) {
            throw new OperationNotPermittedException('A party cannot accept its own proposal');
        }

        if ($proposal->resolution !== null) {
            throw new OperationNotPermittedException('That proposal has already been answered');
        }

        DB::transaction(function () use ($proposal, $dispute, $organizationId, $userId): void {
            $proposal->update(['resolution' => 'ACCEPTED']);

            $this->write(
                $dispute,
                $organizationId,
                $userId,
                MessageType::PROPOSAL_ACCEPTED,
                'پیشنهاد پذیرفته شد',
                respondsTo: (int) $proposal->id,
            );
        });

        // Restate the offer in the case's frame: positive means the claimant
        // receives. If the respondent offered, that is a straight transfer to
        // the claimant; if the claimant offered a concession, it runs the other
        // way and the award is negative.
        $sign = $proposal->sender_org_id === $dispute->respondent_org_id ? 1 : -1;

        return $this->resolutions->decide(
            dispute: $dispute,
            decision: DisputeDecision::SETTLED_BY_AGREEMENT,
            awardedGoldMg: $sign * $proposal->proposed_gold_mg,
            awardedRial: $sign * $proposal->proposed_rial,
            rationale: 'توافق طرفین در مذاکره — پیشنهاد #'.$proposal->id,
            decidedByUserId: $userId,
            actor: new TransitionContext(
                actorType: $organizationId === $dispute->claimant_org_id
                    ? ActorType::CLAIMANT
                    : ActorType::RESPONDENT,
                actorUserId: $userId,
                actorOrgId: $organizationId,
                action: 'SETTLED_BY_AGREEMENT',
            ),
        );
    }

    public function rejectProposal(
        DisputeModel $dispute,
        int $proposalId,
        int $organizationId,
        int $userId,
        string $reason = '',
    ): DisputeMessageModel {
        $this->assertCanSpeak($dispute, $organizationId);

        /** @var ?DisputeMessageModel $proposal */
        $proposal = DisputeMessageModel::query()
            ->where('dispute_id', $dispute->id)
            ->whereKey($proposalId)
            ->first();

        if ($proposal === null || $proposal->message_type !== MessageType::SETTLEMENT_PROPOSAL->value) {
            throw new InvalidArgumentException('No such settlement proposal on this case');
        }

        if ($proposal->resolution !== null) {
            throw new OperationNotPermittedException('That proposal has already been answered');
        }

        $proposal->update(['resolution' => 'REJECTED']);

        return $this->write(
            $dispute,
            $organizationId,
            $userId,
            MessageType::PROPOSAL_REJECTED,
            $reason !== '' ? $reason : 'پیشنهاد رد شد',
            respondsTo: (int) $proposal->id,
        );
    }

    /** @return array<int, DisputeMessageModel> */
    public function transcript(DisputeModel $dispute): array
    {
        return DisputeMessageModel::query()
            ->where('dispute_id', $dispute->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all();
    }

    private function write(
        DisputeModel $dispute,
        int $organizationId,
        int $userId,
        MessageType $type,
        string $body,
        int $goldMg = 0,
        int $rial = 0,
        ?int $respondsTo = null,
    ): DisputeMessageModel {
        /** @var DisputeMessageModel $message */
        $message = DisputeMessageModel::query()->create([
            'dispute_id' => $dispute->id,
            'sender_org_id' => $organizationId,
            'sender_user_id' => $userId,
            'message_type' => $type->value,
            'body' => $body,
            'proposed_gold_mg' => $goldMg,
            'proposed_rial' => $rial,
            'responds_to_message_id' => $respondsTo,
            'created_at' => Carbon::now(),
        ]);

        $this->stateMachine->note($dispute, new TransitionContext(
            actorType: $organizationId === $dispute->claimant_org_id
                ? ActorType::CLAIMANT
                : ActorType::RESPONDENT,
            actorUserId: $userId,
            actorOrgId: $organizationId,
            action: $type->value,
            message: $body,
        ));

        return $message;
    }

    /**
     * The room is open during negotiation, and during mediation so the parties
     * can keep talking while an operator reads along. It is closed once the
     * case reaches a verdict.
     */
    private function assertCanSpeak(DisputeModel $dispute, int $organizationId): void
    {
        if (! $dispute->involves($organizationId)) {
            throw new OperationNotPermittedException('Only a party to the case may take part in negotiation');
        }

        $status = $dispute->statusEnum();

        $open = in_array($status, [
            DisputeStatus::AWAITING_REPLY,
            DisputeStatus::NEGOTIATION,
            DisputeStatus::UNDER_MEDIATION,
            DisputeStatus::AWAITING_EVIDENCE,
            DisputeStatus::AWAITING_REASSAY,
        ], true);

        if (! $open) {
            throw new OperationNotPermittedException(
                'The negotiation room is closed while the case is '.$status->label()
            );
        }
    }
}
