<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Application;

use App\Modules\Settlement\Application\Commands\OpenSettlementCommand;
use App\Modules\Settlement\Domain\SettlementStatus;
use App\Modules\Settlement\Domain\TransitionContext;
use App\Modules\Settlement\Events\PaymentRequired;
use App\Modules\Settlement\Events\SettlementOpened;
use App\Modules\Settlement\Infrastructure\Models\SettlementModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns an executed trade into an open obligation — §5.1 and the first two
 * rows of the §5.2 transition table.
 *
 * The settlement is inserted as CREATED and then walked to ASSETS_LOCKED, so
 * the row exists (and has an id the ledger can reference) before any value
 * moves. The state machine performs the RESERVED → IN_SETTLEMENT move for both
 * sides; that is worked example 1's transaction group g4.
 *
 * Idempotent by trade: a duplicate TradeExecuted delivery returns the existing
 * settlement rather than locking the assets twice. Trading is built
 * concurrently and at-least-once event delivery is the norm, so this is a
 * correctness requirement and not a nicety.
 *
 * The event is fired after commit (AGENT_BRIEF rule 3).
 */
final readonly class OpenSettlementService
{
    public function __construct(private SettlementStateMachine $stateMachine) {}

    public function open(OpenSettlementCommand $command): SettlementModel
    {
        $existing = $this->findForTrade($command);

        if ($existing instanceof SettlementModel) {
            return $existing;
        }

        $settlement = DB::transaction(function () use ($command): SettlementModel {
            $model = SettlementModel::query()->create([
                // Placeholder: the code embeds the id, which auto-increment
                // only reveals after the insert. Overwritten immediately below.
                'settlement_code' => 'STL-PENDING-'.bin2hex(random_bytes(4)),
                'trade_id' => $command->tradeId,
                'settlement_type' => $command->settlementType->value,
                'gold_deliverer_org_id' => $command->goldDelivererOrgId,
                'gold_receiver_org_id' => $command->goldReceiverOrgId,
                'cash_payer_org_id' => $command->cashPayerOrgId,
                'cash_receiver_org_id' => $command->cashReceiverOrgId,
                'fine_weight_mg' => $command->fineWeightMg,
                'cash_amount_rial' => $command->cashAmountRial,
                'buyer_fee_rial' => $command->buyerFeeRial,
                'seller_fee_rial' => $command->sellerFeeRial,
                'delivery_method' => $command->deliveryMethod->value,
                'payment_method' => $command->paymentMethod->value,
                'deadline_at' => $this->deadlineFor($command),
                'parent_settlement_id' => $command->parentSettlementId,
                'status' => SettlementStatus::CREATED->value,
            ]);

            $model->settlement_code = self::codeFor((int) $model->id);
            $model->save();

            return $model;
        }, attempts: 3);

        $this->stateMachine->transition(
            (int) $settlement->id,
            SettlementStatus::ASSETS_LOCKED,
            new TransitionContext(
                actorUserId: $command->actorUserId,
                reason: 'Trade '.$command->tradeId.' executed',
                metadata: ['trade_id' => $command->tradeId],
            ),
        );

        $settlement->refresh();

        event(new SettlementOpened(
            settlementId: (int) $settlement->id,
            settlementCode: (string) $settlement->settlement_code,
            tradeId: $settlement->trade_id,
            goldDelivererOrgId: $settlement->gold_deliverer_org_id,
            goldReceiverOrgId: $settlement->gold_receiver_org_id,
            cashPayerOrgId: $settlement->cash_payer_org_id,
            cashReceiverOrgId: $settlement->cash_receiver_org_id,
            fineWeightMg: $settlement->fine_weight_mg,
            cashAmountRial: $settlement->cash_amount_rial,
            buyerFeeRial: $settlement->buyer_fee_rial,
            sellerFeeRial: $settlement->seller_fee_rial,
            settlementType: $settlement->settlement_type->value,
            deadlineAt: (string) $settlement->deadline_at?->toIso8601String(),
            occurredAt: now()->toIso8601String(),
        ));

        return $settlement;
    }

    /**
     * Move on to PAYMENT_PENDING, the state the member's dashboard shows as
     * "waiting for your payment" (§5.9).
     *
     * Separate from open() because the alternative route out of ASSETS_LOCKED
     * is NETTING_QUEUE, and which one applies is not the trade's decision.
     */
    public function awaitPayment(int $settlementId, ?int $actorUserId = null): SettlementModel
    {
        $this->stateMachine->transition(
            $settlementId,
            SettlementStatus::PAYMENT_PENDING,
            new TransitionContext(actorUserId: $actorUserId, reason: 'Awaiting payment'),
        );

        $settlement = $this->stateMachine->lock($settlementId);

        // "Opened" and "pay now" are two different facts: a settlement can be
        // opened and then routed to the netting queue, where nobody is asked
        // for anything. Only this one should reach a member's phone, and only
        // the payer's.
        event(new PaymentRequired(
            settlementId: (int) $settlement->id,
            settlementCode: (string) $settlement->settlement_code,
            cashPayerOrgId: (int) $settlement->cash_payer_org_id,
            cashReceiverOrgId: (int) $settlement->cash_receiver_org_id,
            // F9 — the gross plus the buyer's fee is what actually leaves.
            amountRial: (int) $settlement->cash_amount_rial + (int) $settlement->buyer_fee_rial,
            deadlineAt: (string) $settlement->deadline_at,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));

        return $settlement;
    }

    /** Open the settlement and put it straight into the payment queue. */
    public function openAndAwaitPayment(OpenSettlementCommand $command): SettlementModel
    {
        $settlement = $this->open($command);

        if ($settlement->status === SettlementStatus::ASSETS_LOCKED) {
            $this->awaitPayment((int) $settlement->id, $command->actorUserId);
            $settlement->refresh();
        }

        return $settlement;
    }

    public static function codeFor(int $settlementId): string
    {
        return sprintf('STL-%08d', $settlementId);
    }

    /**
     * An existing settlement for the same trade, ignoring cancelled ones so a
     * trade whose settlement was called off can legitimately be re-opened.
     */
    private function findForTrade(OpenSettlementCommand $command): ?SettlementModel
    {
        return SettlementModel::query()
            ->where('trade_id', $command->tradeId)
            ->whereNull('parent_settlement_id')
            ->whereNotIn('status', [
                SettlementStatus::CANCELLED->value,
                SettlementStatus::REVERSED->value,
            ])
            ->orderBy('id')
            ->first();
    }

    /**
     * §5.1 deadline_at. The trade dictates it when it knows; otherwise fall
     * back to the settlement type's offset plus the configured window.
     */
    private function deadlineFor(OpenSettlementCommand $command): CarbonImmutable
    {
        if ($command->deadlineAt instanceof CarbonImmutable) {
            return $command->deadlineAt;
        }

        $hours = (int) config('goldb2b.settlement.default_deadline_hours', 8);

        return CarbonImmutable::now()
            ->addDays($command->settlementType->offsetDays())
            ->addHours($hours);
    }
}
