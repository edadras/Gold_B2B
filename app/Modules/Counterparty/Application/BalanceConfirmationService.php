<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Application;

use App\Modules\Counterparty\Contracts\ConfirmationDiscrepancy;
use App\Modules\Counterparty\Contracts\StatementLine;
use App\Modules\Counterparty\Domain\ConfirmationStatus;
use App\Modules\Counterparty\Infrastructure\BalanceConfirmation;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mutual balance confirmation (docs/03-domain/10-counterparty.md §10.4).
 *
 * Sign convention is the trap in this flow. Each side keeps its book from its
 * own point of view, so "you owe me 250 g" and "I owe you 250 g" are the same
 * fact written with opposite signs. Everything stored on the confirmation row
 * is restated into the *requester's* convention, which turns the comparison
 * into a plain subtraction instead of a puzzle that a caller will eventually
 * get backwards.
 */
final class BalanceConfirmationService
{
    /**
     * Ask a counterparty to confirm the balance we hold for them as of a date.
     *
     * The stated figures are computed from the movement log as of that date, not
     * from the live relation row: the request may be raised days later, and the
     * question being asked is about the date, not about right now.
     */
    public function request(
        int $organizationId,
        int $counterpartyOrgId,
        DateTimeInterface|string $asOf,
        ?int $requestedByUserId = null,
        DateTimeInterface|string|null $periodStart = null,
        ?string $note = null,
    ): BalanceConfirmation {
        if ($organizationId === $counterpartyOrgId) {
            throw new OperationNotPermittedException('Cannot request a balance confirmation from yourself.');
        }

        $asOfAt = Carbon::parse($asOf);
        $periodStartAt = $periodStart !== null ? Carbon::parse($periodStart) : null;

        $balance = $this->balanceAsOf($organizationId, $counterpartyOrgId, $asOfAt);

        $confirmation = new BalanceConfirmation;
        $confirmation->fill([
            'organization_id' => $organizationId,
            'counterparty_org_id' => $counterpartyOrgId,
            'period_start' => $periodStartAt?->toDateTimeString(),
            'as_of' => $asOfAt->toDateTimeString(),
            'requester_gold_mg' => $balance['gold_mg'],
            'requester_rial' => $balance['rial'],
            'status' => ConfirmationStatus::PENDING->value,
            'requested_by_user_id' => $requestedByUserId,
            'requester_note' => $note,
        ]);
        $confirmation->save();

        return $confirmation;
    }

    /**
     * The counterparty agrees with the stated figures. No numbers are supplied:
     * agreeing *is* accepting the requester's figures, and letting the responder
     * send its own numbers while claiming agreement invites a silent mismatch.
     */
    public function agree(
        int $confirmationId,
        int $respondingOrgId,
        ?int $respondedByUserId = null,
        ?string $note = null,
    ): BalanceConfirmation {
        $confirmation = $this->lockedConfirmation($confirmationId, $respondingOrgId);

        $confirmation->status->assertCanTransitionTo(ConfirmationStatus::AGREED);

        $confirmation->fill([
            'status' => ConfirmationStatus::AGREED->value,
            // Agreement means the mirror of what the requester stated.
            'responder_gold_mg' => $confirmation->requester_gold_mg,
            'responder_rial' => $confirmation->requester_rial,
            'responded_by_user_id' => $respondedByUserId,
            'responder_note' => $note,
            'responded_at' => Carbon::now()->toDateTimeString(),
        ]);
        $confirmation->save();

        return $confirmation;
    }

    /**
     * The counterparty disagrees and states its own figures, expressed in *its
     * own* convention (positive = the requester owes the responder). They are
     * negated on the way in so the stored pair can be subtracted directly.
     *
     * If the restated figures actually match, the confirmation settles as
     * AGREED — the two sides differed on presentation, not on substance.
     */
    public function dispute(
        int $confirmationId,
        int $respondingOrgId,
        int $ownGoldMg,
        int $ownRial,
        ?int $respondedByUserId = null,
        ?string $note = null,
    ): BalanceConfirmation {
        $confirmation = $this->lockedConfirmation($confirmationId, $respondingOrgId);

        $restatedGold = -$ownGoldMg;
        $restatedRial = -$ownRial;

        $matches = $restatedGold === $confirmation->requester_gold_mg
            && $restatedRial === $confirmation->requester_rial;

        $next = $matches ? ConfirmationStatus::AGREED : ConfirmationStatus::DISPUTED;
        $confirmation->status->assertCanTransitionTo($next);

        $confirmation->fill([
            'status' => $next->value,
            'responder_gold_mg' => $restatedGold,
            'responder_rial' => $restatedRial,
            'responded_by_user_id' => $respondedByUserId,
            'responder_note' => $note,
            'responded_at' => Carbon::now()->toDateTimeString(),
        ]);
        $confirmation->save();

        return $confirmation;
    }

    /**
     * Hand an unresolved discrepancy to the Dispute module. This module records
     * only that the hand-off happened; opening the case is the caller's job,
     * because Counterparty must not depend on Dispute.
     */
    public function escalate(int $confirmationId, int $actingOrgId): BalanceConfirmation
    {
        $confirmation = BalanceConfirmation::query()->findOrFail($confirmationId);

        if ($actingOrgId !== $confirmation->organization_id
            && $actingOrgId !== $confirmation->counterparty_org_id) {
            throw new OperationNotPermittedException('Only a party to the confirmation may escalate it.');
        }

        $confirmation->status->assertCanTransitionTo(ConfirmationStatus::ESCALATED);

        $confirmation->status = ConfirmationStatus::ESCALATED;
        $confirmation->save();

        return $confirmation;
    }

    public function cancel(int $confirmationId, int $requesterOrgId): BalanceConfirmation
    {
        $confirmation = BalanceConfirmation::query()->findOrFail($confirmationId);

        if ($confirmation->organization_id !== $requesterOrgId) {
            throw new OperationNotPermittedException('Only the requester may cancel a confirmation.');
        }

        $confirmation->status->assertCanTransitionTo(ConfirmationStatus::CANCELLED);

        $confirmation->status = ConfirmationStatus::CANCELLED;
        $confirmation->save();

        return $confirmation;
    }

    /**
     * The delta plus both movement lists, so the two sides can find the row that
     * only one of them booked.
     */
    public function discrepancy(int $confirmationId): ConfirmationDiscrepancy
    {
        $confirmation = BalanceConfirmation::query()->findOrFail($confirmationId);

        $asOf = Carbon::parse($confirmation->as_of);
        $periodStart = $confirmation->period_start !== null
            ? Carbon::parse($confirmation->period_start)
            : Carbon::createFromTimestamp(0);

        $ours = $this->movements(
            $confirmation->organization_id,
            $confirmation->counterparty_org_id,
            $periodStart,
            $asOf,
            negate: false,
        );

        // The counterparty's own rows, negated so both lists read in the same
        // direction and a missing movement stands out by amount, not by sign.
        $theirs = $this->movements(
            $confirmation->counterparty_org_id,
            $confirmation->organization_id,
            $periodStart,
            $asOf,
            negate: true,
        );

        $goldDelta = $confirmation->responder_gold_mg !== null
            ? $confirmation->requester_gold_mg - $confirmation->responder_gold_mg
            : null;
        $rialDelta = $confirmation->responder_rial !== null
            ? $confirmation->requester_rial - $confirmation->responder_rial
            : null;

        return new ConfirmationDiscrepancy(
            confirmationId: $confirmation->id,
            organizationId: $confirmation->organization_id,
            counterpartyOrgId: $confirmation->counterparty_org_id,
            status: $confirmation->status->value,
            periodStart: $periodStart->toIso8601String(),
            asOf: $asOf->toIso8601String(),
            requesterGoldMg: $confirmation->requester_gold_mg,
            requesterRial: $confirmation->requester_rial,
            responderGoldMg: $confirmation->responder_gold_mg,
            responderRial: $confirmation->responder_rial,
            goldDeltaMg: $goldDelta,
            rialDelta: $rialDelta,
            requesterMovements: $ours,
            counterpartyMovements: $theirs,
        );
    }

    /** @return list<BalanceConfirmation> */
    public function inbox(int $organizationId): array
    {
        return BalanceConfirmation::query()
            ->where('counterparty_org_id', $organizationId)
            ->where('status', ConfirmationStatus::PENDING->value)
            ->orderBy('created_at')
            ->get()
            ->all();
    }

    /** @return array{gold_mg: int, rial: int} */
    private function balanceAsOf(int $organizationId, int $counterpartyOrgId, Carbon $asOf): array
    {
        $row = DB::table('counterparty_movements')
            ->where('organization_id', $organizationId)
            ->where('counterparty_org_id', $counterpartyOrgId)
            ->where('occurred_at', '<=', $asOf->toDateTimeString())
            ->selectRaw('COALESCE(SUM(gold_delta_mg), 0) AS gold_mg, COALESCE(SUM(rial_delta), 0) AS rial')
            ->first();

        return [
            'gold_mg' => (int) ($row->gold_mg ?? 0),
            'rial' => (int) ($row->rial ?? 0),
        ];
    }

    /** @return list<StatementLine> */
    private function movements(
        int $organizationId,
        int $counterpartyOrgId,
        Carbon $from,
        Carbon $to,
        bool $negate,
    ): array {
        $rows = DB::table('counterparty_movements')
            ->where('organization_id', $organizationId)
            ->where('counterparty_org_id', $counterpartyOrgId)
            ->whereBetween('occurred_at', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $sign = $negate ? -1 : 1;
        $runningGold = 0;
        $runningRial = 0;
        $lines = [];

        foreach ($rows as $row) {
            $gold = $sign * (int) $row->gold_delta_mg;
            $rial = $sign * (int) $row->rial_delta;
            $runningGold += $gold;
            $runningRial += $rial;

            $lines[] = new StatementLine(
                movementId: (int) $row->id,
                occurredAt: (string) $row->occurred_at,
                kind: (string) $row->kind,
                reference: $row->reference !== null ? (string) $row->reference : null,
                description: $row->description !== null ? (string) $row->description : null,
                goldDeltaMg: $gold,
                rialDelta: $rial,
                runningGoldMg: $runningGold,
                runningRial: $runningRial,
            );
        }

        return $lines;
    }

    private function lockedConfirmation(int $confirmationId, int $respondingOrgId): BalanceConfirmation
    {
        $confirmation = BalanceConfirmation::query()->findOrFail($confirmationId);

        if ($confirmation->counterparty_org_id !== $respondingOrgId) {
            throw new OperationNotPermittedException(
                'Only the addressed counterparty may answer a balance confirmation.'
            );
        }

        return $confirmation;
    }
}
