<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Application;

use App\Modules\Ledger\Contracts\GroupWriter;
use App\Modules\Ledger\Contracts\LedgerInterface;
use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\EntryType;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Ledger\Domain\SystemAccountCode;
use App\Modules\Settlement\Domain\Exceptions\SettlementNotFoundException;
use App\Modules\Settlement\Domain\SettlementStatus;
use App\Modules\Settlement\Domain\TransitionContext;
use App\Modules\Settlement\Infrastructure\Models\SettlementEventModel;
use App\Modules\Settlement\Infrastructure\Models\SettlementModel;
use App\Modules\Shared\Exceptions\InvalidStateTransitionException;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Rial;
use Illuminate\Support\Facades\DB;

/**
 * The only thing in the platform allowed to change settlements.status.
 *
 * Three responsibilities, all inside one database transaction so a status can
 * never exist without its justification or its ledger consequence:
 *
 *   1. validate the transition against SettlementStatus::allowedTransitions()
 *      (appendix §2.14 rule 2 — no arbitrary status change, ever);
 *   2. append a settlement_events row recording who, when and why (rule 1);
 *   3. apply the ledger side effect that docs/03-domain/05-settlement.md §5.2
 *      attaches to the transition (rule 6 — a financial transition writes to
 *      the ledger inside the same transaction).
 *
 * The §5.2 table, and where each row is implemented:
 *
 *   CREATED → ASSETS_LOCKED         RESERVED → IN_SETTLEMENT   lockAssets()
 *   PAYMENT_DECLARED → PAYMENT_CONFIRMED   cash + fees         settleCash()
 *   GOLD_TRANSFERRING → SETTLED     gold + ownership           dischargeHoldings()
 *   any → CANCELLED                 IN_SETTLEMENT → AVAILABLE  releaseHoldings()
 *   any → DISPUTED                  hold in IN_DISPUTE         holdForDispute()
 *   any → REVERSED                  reversing entries          ReversalService
 *   NETTING_QUEUE → SETTLED         one large group            NettingService
 *
 * The last two are posted by their services and arrive here with
 * TransitionContext::applyLedgerEffects false: a netting batch writes ONE group
 * for every settlement in it, and a reversal needs the dual-control identities.
 * Bookkeeping columns are still maintained in both cases — only the posting is
 * skipped — so locked_gold_mg and friends stay truthful whichever path ran.
 *
 * Events are NOT fired here. Callers fire them after commit (AGENT_BRIEF
 * rule 3); this class runs inside the transaction.
 */
final readonly class SettlementStateMachine
{
    public function __construct(private LedgerInterface $ledger) {}

    /**
     * Move a settlement to a new status.
     *
     * @return ?string the transaction_group the side effect produced, if any
     *
     * @throws InvalidStateTransitionException
     */
    public function transition(
        int|SettlementModel $settlement,
        SettlementStatus $target,
        TransitionContext $context,
    ): ?string {
        $settlementId = $settlement instanceof SettlementModel ? (int) $settlement->id : $settlement;

        return DB::transaction(function () use ($settlementId, $target, $context): ?string {
            $model = $this->lock($settlementId);
            $current = $model->status;

            if (! $current->canTransitionTo($target)) {
                throw new InvalidStateTransitionException('Settlement', $current->value, $target->value);
            }

            $group = $context->applyLedgerEffects
                ? $this->applySideEffects($model, $current, $target)
                : null;

            $this->applyBookkeeping($model, $target);

            $model->status = $target;
            $model->version = $model->version + 1;
            $model->save();

            SettlementEventModel::query()->create([
                'settlement_id' => $model->id,
                'from_status' => $current->value,
                'to_status' => $target->value,
                'actor_type' => $context->actorType->value,
                'actor_user_id' => $context->actorUserId,
                'reason' => $context->reason,
                'transaction_group' => $group ?? $context->transactionGroup,
                'metadata' => $context->metadata,
            ]);

            return $group ?? $context->transactionGroup;
        }, attempts: 3);
    }

    /** Lock in ascending id order (AGENT_BRIEF rule 4) — a single row here. */
    public function lock(int $settlementId): SettlementModel
    {
        $model = SettlementModel::query()->whereKey($settlementId)->lockForUpdate()->first();

        if (! $model instanceof SettlementModel) {
            throw new SettlementNotFoundException('Settlement', $settlementId);
        }

        return $model;
    }

    // ── §5.2 side effects ────────────────────────────────────────────────────

    private function applySideEffects(
        SettlementModel $s,
        SettlementStatus $from,
        SettlementStatus $to,
    ): ?string {
        return match (true) {
            $to === SettlementStatus::ASSETS_LOCKED => $this->lockAssets($s),
            $to === SettlementStatus::PAYMENT_CONFIRMED => $this->settleCash($s),
            $to === SettlementStatus::SETTLED => $this->dischargeHoldings($s),
            $to === SettlementStatus::CANCELLED => $this->releaseHoldings($s),
            $to === SettlementStatus::DISPUTED => $this->holdForDispute($s),
            default => null,
        };
    }

    /**
     * CREATED → ASSETS_LOCKED. Both sides' reservations become settlement
     * holdings: worked example 1, transaction group g4.
     *
     * The gold leg belongs to the deliverer, the cash leg to the payer, so this
     * is two groups and not one — they touch different organisations and
     * different assets, and the ledger's per-group balance check is satisfied
     * by each independently.
     */
    private function lockAssets(SettlementModel $s): ?string
    {
        $group = null;

        if ($s->fine_weight_mg > 0) {
            $group = $this->ledger->moveBucket(
                $s->gold_deliverer_org_id,
                Bucket::RESERVED,
                Bucket::IN_SETTLEMENT,
                FineWeight::fromMilligrams($s->fine_weight_mg),
                $this->reference($s),
                EntryType::SETTLEMENT_LOCK,
            )->value;
        }

        $cash = $s->totalCashDue();

        if (! $cash->isZero()) {
            $group = $this->ledger->moveBucket(
                $s->cash_payer_org_id,
                Bucket::RESERVED,
                Bucket::IN_SETTLEMENT,
                $cash,
                $this->reference($s),
                EntryType::SETTLEMENT_LOCK,
            )->value;
        }

        $s->locked_gold_mg = $s->fine_weight_mg;
        $s->locked_cash_rial = $cash->amount;
        $s->held_bucket = Bucket::IN_SETTLEMENT->value;

        return $group;
    }

    /**
     * PAYMENT_DECLARED → PAYMENT_CONFIRMED — "انتقال ریال".
     *
     * Worked example 1, group g5: the payer's IN_SETTLEMENT cash leaves, the
     * seller receives gross minus their fee, and both fees land in
     * SYSTEM/FEE_INCOME. Four legs, Σ = 0.
     */
    private function settleCash(SettlementModel $s): ?string
    {
        if ($s->locked_cash_rial <= 0) {
            return null;
        }

        $group = $this->ledger->postGroup(function (GroupWriter $writer) use ($s): void {
            $this->writeCashLegs($writer, $s);
        });

        $s->locked_cash_rial = 0;
        $s->payment_confirmed_at = now();

        return $group->value;
    }

    /**
     * → SETTLED. Whatever this settlement still holds is discharged to the
     * counterparties in one balanced group.
     *
     * On the normal path only gold is left, because the cash went at
     * PAYMENT_CONFIRMED — that is exactly worked example 1's group g6. The
     * general form also covers DISPUTED → SETTLED and DEFAULTED → SETTLED,
     * where cash may still be sitting in IN_DISPUTE or IN_SETTLEMENT, and it
     * reads the source bucket from the settlement so a disputed hold is
     * released from IN_DISPUTE rather than IN_SETTLEMENT.
     */
    private function dischargeHoldings(SettlementModel $s): ?string
    {
        if (! $s->hasLockedAssets()) {
            return null;
        }

        $bucket = $s->heldBucket();

        $group = $this->ledger->postGroup(function (GroupWriter $writer) use ($s, $bucket): void {
            if ($s->locked_gold_mg > 0) {
                $writer->post(
                    $s->gold_deliverer_org_id,
                    AssetType::GOLD,
                    $bucket,
                    -$s->locked_gold_mg,
                    EntryType::TRADE_SELL_GOLD,
                    $this->reference($s),
                );
                $writer->post(
                    $s->gold_receiver_org_id,
                    AssetType::GOLD,
                    Bucket::AVAILABLE,
                    $s->locked_gold_mg,
                    EntryType::TRADE_BUY_GOLD,
                    $this->reference($s),
                );
            }

            if ($s->locked_cash_rial > 0) {
                $this->writeCashLegs($writer, $s, $bucket);
            }
        });

        $s->locked_gold_mg = 0;
        $s->locked_cash_rial = 0;
        $s->held_bucket = null;

        return $group->value;
    }

    /**
     * any pre-SETTLED state → CANCELLED — "آزادسازی قفل‌ها".
     *
     * Both sides get their assets back where they came from. Nothing is
     * transferred, so this is a bucket move per organisation and not a group.
     */
    private function releaseHoldings(SettlementModel $s): ?string
    {
        $group = null;
        $bucket = $s->heldBucket();

        if ($s->locked_gold_mg > 0) {
            $group = $this->ledger->moveBucket(
                $s->gold_deliverer_org_id,
                $bucket,
                Bucket::AVAILABLE,
                FineWeight::fromMilligrams($s->locked_gold_mg),
                $this->reference($s),
                EntryType::SETTLEMENT_RELEASE,
            )->value;
        }

        if ($s->locked_cash_rial > 0) {
            $group = $this->ledger->moveBucket(
                $s->cash_payer_org_id,
                $bucket,
                Bucket::AVAILABLE,
                Rial::fromRial($s->locked_cash_rial),
                $this->reference($s),
                EntryType::SETTLEMENT_RELEASE,
            )->value;
        }

        $s->locked_gold_mg = 0;
        $s->locked_cash_rial = 0;
        $s->held_bucket = null;

        return $group;
    }

    /**
     * any → DISPUTED — "قفل مبلغ مورد اختلاف در IN_DISPUTE".
     *
     * The disputed value is quarantined so neither party can spend it while the
     * dispute runs. A settlement that has already discharged its holdings has
     * nothing to quarantine; Dispute freezes against the members' balances in
     * that case, which is its own module's job.
     */
    private function holdForDispute(SettlementModel $s): ?string
    {
        if ($s->heldBucket() === Bucket::IN_DISPUTE || ! $s->hasLockedAssets()) {
            return null;
        }

        $group = null;

        if ($s->locked_gold_mg > 0) {
            $group = $this->ledger->moveBucket(
                $s->gold_deliverer_org_id,
                Bucket::IN_SETTLEMENT,
                Bucket::IN_DISPUTE,
                FineWeight::fromMilligrams($s->locked_gold_mg),
                $this->reference($s),
                EntryType::DISPUTE_HOLD,
            )->value;
        }

        if ($s->locked_cash_rial > 0) {
            $group = $this->ledger->moveBucket(
                $s->cash_payer_org_id,
                Bucket::IN_SETTLEMENT,
                Bucket::IN_DISPUTE,
                Rial::fromRial($s->locked_cash_rial),
                $this->reference($s),
                EntryType::DISPUTE_HOLD,
            )->value;
        }

        $s->held_bucket = Bucket::IN_DISPUTE->value;

        return $group;
    }

    /**
     * The four cash legs of a settled payment: payer −(gross + buyer fee),
     * seller +(gross − seller fee), FEE_INCOME + both fees.
     *
     * Σ = −(g + b) + (g − s) + b + s = 0, which is what makes worked example 1
     * group g5 balance.
     */
    private function writeCashLegs(GroupWriter $writer, SettlementModel $s, ?Bucket $from = null): void
    {
        $ref = $this->reference($s);

        $writer->post(
            $s->cash_payer_org_id,
            AssetType::RIAL,
            $from ?? Bucket::IN_SETTLEMENT,
            -$s->locked_cash_rial,
            EntryType::TRADE_BUY_CASH,
            $ref,
        );

        $proceeds = $s->locked_cash_rial - $s->buyer_fee_rial - $s->seller_fee_rial;

        $writer->post(
            $s->cash_receiver_org_id,
            AssetType::RIAL,
            Bucket::AVAILABLE,
            $proceeds,
            EntryType::TRADE_SELL_CASH,
            $ref,
        );

        foreach ([$s->buyer_fee_rial, $s->seller_fee_rial] as $fee) {
            if ($fee > 0) {
                $writer->postSystem(
                    SystemAccountCode::FEE_INCOME,
                    AssetType::RIAL,
                    $fee,
                    EntryType::FEE_INCOME,
                    $ref,
                );
            }
        }
    }

    // ── bookkeeping that runs whoever posted the ledger ──────────────────────

    private function applyBookkeeping(SettlementModel $s, SettlementStatus $to): void
    {
        if ($to === SettlementStatus::SETTLED) {
            $this->markSettled($s);

            return;
        }

        if ($to === SettlementStatus::COMPLETED) {
            $s->completed_at = now();

            return;
        }

        if ($to === SettlementStatus::OVERDUE && $s->overdue_since === null) {
            $s->overdue_since = now();

            return;
        }

        if ($to === SettlementStatus::REVERSED) {
            $s->reversed_at = now();
        }
    }

    /**
     * SETTLED starts the 24-hour objection window of §5.2. Holdings are zeroed
     * here too, because a netting batch or a reversal may have moved them
     * without going through applySideEffects().
     */
    private function markSettled(SettlementModel $s): void
    {
        if ($s->settled_at === null) {
            $s->settled_at = now();
        }

        $s->locked_gold_mg = 0;
        $s->locked_cash_rial = 0;
        $s->held_bucket = null;
    }

    private function reference(SettlementModel $s): LedgerReference
    {
        return LedgerReference::settlement((int) $s->id);
    }
}
