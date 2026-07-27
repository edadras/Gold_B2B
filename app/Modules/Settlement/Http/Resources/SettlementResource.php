<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Http\Resources;

use App\Modules\Settlement\Domain\SettlementStatus;
use App\Modules\Settlement\Infrastructure\Models\PaymentModel;
use App\Modules\Settlement\Infrastructure\Models\SettlementModel;
use App\Modules\Shared\Contracts\PayoutAccount;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * CONTRACT GAP RESOLVED (#2). The settlement resource shape was undocumented,
 * so the Flutter payment-declaration screen had to guess — and had nowhere at
 * all to get the payee's account from, because Settlement does not store one.
 *
 * The shape below is now the contract. Three decisions worth stating:
 *
 * 1. **Roles, not raw party columns.** A settlement carries four organisation
 *    ids (gold deliverer, gold receiver, cash payer, cash receiver) and in a
 *    netted batch they are not two organisations swapping sides. Rather than
 *    make the client work out which one it is, the resource publishes
 *    `my_roles`, `counterparty_organization_id` and `awaiting_me`.
 *
 * 2. **`destination_account` is present only for the payer, and only while
 *    payment is outstanding.** It comes from Kyc through the
 *    PayoutAccountDirectory port. The full IBAN is revealed exclusively to the
 *    cash payer of THIS settlement while it is still awaiting payment — that is
 *    precisely the party who needs it, at precisely the moment they need it.
 *    Everybody else, including the payer once the money has cleared, sees the
 *    masked form. A settlement is the only context in which one member is
 *    entitled to another member's bank details, so the reveal is scoped to it.
 *
 * 3. **`amount_due_rial` is computed, not stored.** It is
 *    `cash_amount_rial + buyer_fee_rial`, and the client must not have to know
 *    that the buyer's fee rides along with the principal.
 *
 * @mixin SettlementModel
 */
final class SettlementResource extends ApiResource
{
    public function __construct(
        mixed $resource,
        private readonly int $viewerOrganizationId,
        private readonly ?PayoutAccount $destinationAccount = null,
        private readonly ?PaymentModel $latestPayment = null,
    ) {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SettlementModel $settlement */
        $settlement = $this->resource;

        $isCashPayer = (int) $settlement->cash_payer_org_id === $this->viewerOrganizationId;
        $isCashReceiver = (int) $settlement->cash_receiver_org_id === $this->viewerOrganizationId;
        $isGoldDeliverer = (int) $settlement->gold_deliverer_org_id === $this->viewerOrganizationId;
        $isGoldReceiver = (int) $settlement->gold_receiver_org_id === $this->viewerOrganizationId;

        $payload = [
            'id' => (int) $settlement->id,
            'settlement_code' => (string) $settlement->settlement_code,
            'trade_id' => (int) $settlement->trade_id,
            'settlement_type' => $settlement->settlement_type->value,
            'status' => $settlement->status->value,
            'status_label' => $settlement->status->label(),

            'my_roles' => array_values(array_filter([
                $isCashPayer ? 'CASH_PAYER' : null,
                $isCashReceiver ? 'CASH_RECEIVER' : null,
                $isGoldDeliverer ? 'GOLD_DELIVERER' : null,
                $isGoldReceiver ? 'GOLD_RECEIVER' : null,
            ])),
            'counterparty_organization_id' => $this->counterpartyId($settlement),
            'awaiting_me' => $this->awaitingViewer($settlement, $isCashPayer, $isCashReceiver, $isGoldDeliverer),

            'fine_weight_mg' => (int) $settlement->fine_weight_mg,
            'cash_amount_rial' => (int) $settlement->cash_amount_rial,
            'buyer_fee_rial' => (int) $settlement->buyer_fee_rial,
            'seller_fee_rial' => (int) $settlement->seller_fee_rial,
            'amount_due_rial' => (int) $settlement->cash_amount_rial + (int) $settlement->buyer_fee_rial,
            'seller_proceeds_rial' => (int) $settlement->cash_amount_rial - (int) $settlement->seller_fee_rial,
            'penalty_rial' => (int) $settlement->penalty_rial,

            'delivery_method' => $settlement->delivery_method->value,
            'payment_method' => $settlement->payment_method->value,
            'payment_reference' => $settlement->payment_reference,
            'bank_transaction_id' => $settlement->bank_transaction_id,
            'allocated_lot_ids' => $settlement->allocated_lot_ids ?? [],

            'locked_gold_mg' => (int) $settlement->locked_gold_mg,
            'locked_cash_rial' => (int) $settlement->locked_cash_rial,
            'escalation_level' => (int) $settlement->escalation_level,
            'netting_batch_id' => $settlement->netting_batch_id === null
                ? null
                : (int) $settlement->netting_batch_id,
            'dispute_id' => $settlement->dispute_id === null ? null : (int) $settlement->dispute_id,

            'deadline_at' => Display::iso($settlement->deadline_at),
            'overdue_since' => Display::iso($settlement->overdue_since),
            'payment_declared_at' => Display::iso($settlement->payment_declared_at),
            'payment_confirmed_at' => Display::iso($settlement->payment_confirmed_at),
            'gold_transferred_at' => Display::iso($settlement->gold_transferred_at),
            'settled_at' => Display::iso($settlement->settled_at),
            'completed_at' => Display::iso($settlement->completed_at),
        ];

        $payload['destination_account'] = $this->destination($settlement, $isCashPayer);
        $payload['latest_payment'] = $this->payment();

        return $payload + $this->display($request, [
            'fine_weight_display' => Display::grams((int) $settlement->fine_weight_mg),
            'amount_due_display' => Display::rial(
                (int) $settlement->cash_amount_rial + (int) $settlement->buyer_fee_rial
            ),
            'penalty_display' => Display::rial((int) $settlement->penalty_rial),
            'deadline_at_jalali' => Display::jalali($settlement->deadline_at),
            'settled_at_jalali' => Display::jalali($settlement->settled_at),
        ]);
    }

    /**
     * The account the payer must send money to.
     *
     * Null when the viewer is not the payer, when there is no verified account,
     * or when there is nothing left to pay — a settled settlement has no reason
     * to keep publishing anybody's IBAN.
     *
     * @return array<string, mixed>|null
     */
    private function destination(SettlementModel $settlement, bool $isCashPayer): ?array
    {
        if ($this->destinationAccount === null) {
            return null;
        }

        $stillOwed = in_array($settlement->status, [
            SettlementStatus::CREATED,
            SettlementStatus::ASSETS_LOCKED,
            SettlementStatus::PAYMENT_PENDING,
            SettlementStatus::PAYMENT_DECLARED,
            SettlementStatus::OVERDUE,
        ], true);

        $reveal = $isCashPayer && $stillOwed;

        return $this->destinationAccount->toArray(revealIban: $reveal) + [
            'organization_id' => (int) $settlement->cash_receiver_org_id,
        ];
    }

    /** @return array<string, mixed>|null */
    private function payment(): ?array
    {
        if ($this->latestPayment === null) {
            return null;
        }

        return [
            'id' => (int) $this->latestPayment->id,
            'amount_rial' => (int) $this->latestPayment->amount_rial,
            'status' => (string) $this->latestPayment->status,
            'payment_reference' => $this->latestPayment->payment_reference,
            'bank_transaction_id' => $this->latestPayment->bank_transaction_id,
            'has_receipt' => $this->latestPayment->hasReceipt(),
            'paid_at' => Display::iso($this->latestPayment->paid_at),
            'declared_at' => Display::iso($this->latestPayment->declared_at),
            'confirmed_at' => Display::iso($this->latestPayment->confirmed_at),
            'rejected_at' => Display::iso($this->latestPayment->rejected_at),
            'rejection_reason' => $this->latestPayment->rejection_reason,
        ];
    }

    /**
     * The other side, from the viewer's point of view.
     *
     * Null in a netted settlement, where the counterparty is the clearing
     * account rather than another member.
     */
    private function counterpartyId(SettlementModel $settlement): ?int
    {
        foreach ([
            (int) $settlement->cash_payer_org_id,
            (int) $settlement->cash_receiver_org_id,
            (int) $settlement->gold_deliverer_org_id,
            (int) $settlement->gold_receiver_org_id,
        ] as $party) {
            if ($party !== $this->viewerOrganizationId && $party !== 0) {
                return $party;
            }
        }

        return null;
    }

    private function awaitingViewer(
        SettlementModel $settlement,
        bool $isCashPayer,
        bool $isCashReceiver,
        bool $isGoldDeliverer,
    ): bool {
        return match ($settlement->status) {
            SettlementStatus::PAYMENT_PENDING, SettlementStatus::OVERDUE => $isCashPayer,
            SettlementStatus::PAYMENT_DECLARED => $isCashReceiver,
            SettlementStatus::PAYMENT_CONFIRMED, SettlementStatus::GOLD_TRANSFERRING => $isGoldDeliverer,
            default => false,
        };
    }
}
