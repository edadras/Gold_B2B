<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Application;

use App\Modules\Settlement\Domain\SettlementStatus;
use App\Modules\Settlement\Domain\TransitionContext;
use App\Modules\Settlement\Events\PaymentConfirmed;
use App\Modules\Settlement\Events\PaymentDeclared;
use App\Modules\Settlement\Infrastructure\Models\PaymentModel;
use App\Modules\Settlement\Infrastructure\Models\SettlementModel;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The two-sided confirmation of docs/03-domain/05-settlement.md §5.3 pattern 2
 * — the model phase 1 and 2 actually run on.
 *
 * The platform does not hold members' cash (ADR-008). The money moves through
 * the banking system, outside this software entirely; what happens here is that
 * the payer asserts they sent it and the payee counter-signs that they got it.
 * Only the counter-signature moves rial in the ledger. That asymmetry is the
 * whole design: a declaration is evidence, a confirmation is settlement.
 *
 * The residual risk §5.3 names is a payee who receives the money and does not
 * confirm. The mitigations are the deadline, the reminder ladder, the receipt
 * upload, reputation, and ultimately Dispute — not custody of the funds.
 */
final readonly class PaymentService
{
    public function __construct(private SettlementStateMachine $stateMachine) {}

    /**
     * Step 3 of §5.3 pattern 2: "I paid, here is the tracking number."
     *
     * Writes no ledger entries — the document is explicit that nothing has
     * moved yet. Accepts an optional receipt path as the evidence §5.3 allows
     * the payer to attach.
     */
    public function declarePayment(
        int $settlementId,
        int $actorUserId,
        ?string $paymentReference = null,
        ?int $amountRial = null,
        ?CarbonImmutable $paidAt = null,
        ?string $receiptPath = null,
        ?string $bankTransactionId = null,
    ): PaymentModel {
        /** @var array{0: PaymentModel, 1: SettlementModel} $result */
        $result = DB::transaction(function () use (
            $settlementId,
            $actorUserId,
            $paymentReference,
            $amountRial,
            $paidAt,
            $receiptPath,
            $bankTransactionId,
        ): array {
            $settlement = $this->stateMachine->lock($settlementId);

            $this->assertDeclarable($settlement);

            $amount = $amountRial ?? $settlement->totalCashDue()->amount;

            if ($amount <= 0 || $amount > $settlement->totalCashDue()->amount) {
                throw new OperationNotPermittedException(
                    'A declared payment must be positive and no more than the amount due'
                );
            }

            $payment = PaymentModel::query()->create([
                'settlement_id' => $settlement->id,
                'payer_organization_id' => $settlement->cash_payer_org_id,
                'payee_organization_id' => $settlement->cash_receiver_org_id,
                'amount_rial' => $amount,
                'payment_method' => $settlement->payment_method->value,
                'payment_reference' => $paymentReference,
                'bank_transaction_id' => $bankTransactionId,
                'receipt_path' => $receiptPath,
                'status' => PaymentModel::DECLARED,
                'paid_at' => $paidAt,
                'declared_at' => now(),
                'declared_by_user_id' => $actorUserId,
            ]);

            // ASSETS_LOCKED → PAYMENT_PENDING is automatic (§5.2); a payer who
            // declares straight after the trade should not have to wait for a
            // sweep to move the settlement into the queue first.
            if ($settlement->status === SettlementStatus::ASSETS_LOCKED) {
                $this->stateMachine->transition(
                    $settlementId,
                    SettlementStatus::PAYMENT_PENDING,
                    TransitionContext::system('Payment window opened'),
                );
            }

            $this->stateMachine->transition(
                $settlementId,
                SettlementStatus::PAYMENT_DECLARED,
                TransitionContext::user(
                    $actorUserId,
                    'Payment declared by payer',
                    ['payment_id' => (int) $payment->id, 'reference' => $paymentReference],
                ),
            );

            $fresh = $this->stateMachine->lock($settlementId);
            $fresh->payment_reference = $paymentReference;
            $fresh->bank_transaction_id = $bankTransactionId;
            $fresh->payment_declared_at = now();
            $fresh->save();

            return [$payment, $fresh];
        }, attempts: 3);

        [$payment, $settlement] = $result;

        event(new PaymentDeclared(
            settlementId: (int) $settlement->id,
            paymentId: (int) $payment->id,
            payerOrganizationId: $settlement->cash_payer_org_id,
            payeeOrganizationId: $settlement->cash_receiver_org_id,
            amountRial: $payment->amount_rial,
            paymentMethod: $payment->payment_method->value,
            paymentReference: $paymentReference,
            hasReceipt: $payment->hasReceipt(),
            declaredByUserId: $actorUserId,
            occurredAt: now()->toIso8601String(),
        ));

        return $payment;
    }

    /**
     * Step 4 of §5.3 pattern 2: "received." This is what actually moves rial.
     *
     * The state machine posts worked example 1's group g5 — payer out of
     * IN_SETTLEMENT, seller in at gross minus their fee, both fees to
     * SYSTEM/FEE_INCOME — and then PAYMENT_CONFIRMED → GOLD_TRANSFERRING runs
     * automatically, because §5.2 marks that transition خودکار.
     */
    public function confirmPayment(
        int $settlementId,
        int $actorUserId,
        ?int $paymentId = null,
    ): SettlementModel {
        /** @var array{0: SettlementModel, 1: ?PaymentModel, 2: ?string} $result */
        $result = DB::transaction(function () use ($settlementId, $actorUserId, $paymentId): array {
            $settlement = $this->stateMachine->lock($settlementId);

            if ($settlement->status !== SettlementStatus::PAYMENT_DECLARED) {
                throw new OperationNotPermittedException(
                    'Only a declared payment can be confirmed; settlement is '.$settlement->status->value
                );
            }

            $payment = $this->pendingPayment($settlementId, $paymentId);

            $group = $this->stateMachine->transition(
                $settlementId,
                SettlementStatus::PAYMENT_CONFIRMED,
                TransitionContext::user(
                    $actorUserId,
                    'Payment confirmed by payee',
                    $payment instanceof PaymentModel ? ['payment_id' => (int) $payment->id] : null,
                ),
            );

            if ($payment instanceof PaymentModel) {
                $payment->status = PaymentModel::CONFIRMED;
                $payment->confirmed_at = now();
                $payment->confirmed_by_user_id = $actorUserId;
                $payment->transaction_group = $group;
                $payment->save();
            }

            // §5.2: PAYMENT_CONFIRMED → GOLD_TRANSFERRING is automatic.
            $this->stateMachine->transition(
                $settlementId,
                SettlementStatus::GOLD_TRANSFERRING,
                TransitionContext::system('Cash settled; delivering gold'),
            );

            return [$this->stateMachine->lock($settlementId), $payment, $group];
        }, attempts: 3);

        [$settlement, $payment, $group] = $result;

        event(new PaymentConfirmed(
            settlementId: (int) $settlement->id,
            paymentId: $payment instanceof PaymentModel ? (int) $payment->id : 0,
            payerOrganizationId: $settlement->cash_payer_org_id,
            payeeOrganizationId: $settlement->cash_receiver_org_id,
            amountRial: $settlement->totalCashDue()->amount,
            buyerFeeRial: $settlement->buyer_fee_rial,
            sellerFeeRial: $settlement->seller_fee_rial,
            confirmedByUserId: $actorUserId,
            transactionGroup: $group,
            occurredAt: now()->toIso8601String(),
        ));

        return $settlement;
    }

    /**
     * The payee disputes the declaration instead of confirming it. The
     * settlement drops back to PAYMENT_PENDING so the payer can correct their
     * reference and declare again; §5.3 escalates to Dispute after 24 hours,
     * which is DisputeService's business, not this one's.
     */
    public function rejectDeclaration(
        int $settlementId,
        int $actorUserId,
        string $reason,
        ?int $paymentId = null,
    ): PaymentModel {
        return DB::transaction(function () use ($settlementId, $actorUserId, $reason, $paymentId): PaymentModel {
            $settlement = $this->stateMachine->lock($settlementId);

            if ($settlement->status !== SettlementStatus::PAYMENT_DECLARED) {
                throw new OperationNotPermittedException('There is no declared payment to reject');
            }

            $payment = $this->pendingPayment($settlementId, $paymentId);

            if (! $payment instanceof PaymentModel) {
                throw new OperationNotPermittedException('There is no declared payment to reject');
            }

            $payment->status = PaymentModel::REJECTED;
            $payment->rejected_at = now();
            $payment->rejected_by_user_id = $actorUserId;
            $payment->rejection_reason = $reason;
            $payment->save();

            // PAYMENT_DECLARED cannot go back to PAYMENT_PENDING (§2.4), so a
            // rejected declaration parks the settlement in DISPUTED, which is
            // the state the document routes a contested payment to.
            $this->stateMachine->transition(
                $settlementId,
                SettlementStatus::DISPUTED,
                TransitionContext::user($actorUserId, 'Payment declaration rejected: '.$reason),
            );

            return $payment;
        }, attempts: 3);
    }

    private function pendingPayment(int $settlementId, ?int $paymentId): ?PaymentModel
    {
        $query = PaymentModel::query()
            ->where('settlement_id', $settlementId)
            ->where('status', PaymentModel::DECLARED)
            ->orderByDesc('id')
            ->lockForUpdate();

        if ($paymentId !== null) {
            $query->whereKey($paymentId);
        }

        return $query->first();
    }

    private function assertDeclarable(SettlementModel $settlement): void
    {
        $declarable = [
            SettlementStatus::ASSETS_LOCKED,
            SettlementStatus::PAYMENT_PENDING,
            SettlementStatus::OVERDUE,
        ];

        if (! in_array($settlement->status, $declarable, true)) {
            throw new OperationNotPermittedException(
                'A payment cannot be declared while the settlement is '.$settlement->status->value
            );
        }
    }
}
