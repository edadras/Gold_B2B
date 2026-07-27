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
use App\Modules\Settlement\Domain\BilateralNet;
use App\Modules\Settlement\Domain\Exceptions\NettingNotAcceptedException;
use App\Modules\Settlement\Domain\Exceptions\SettlementAlreadyNettedException;
use App\Modules\Settlement\Domain\NetPosition;
use App\Modules\Settlement\Domain\NettingBatchStatus;
use App\Modules\Settlement\Domain\NettingCalculator;
use App\Modules\Settlement\Domain\NettingType;
use App\Modules\Settlement\Domain\Obligation;
use App\Modules\Settlement\Domain\SettlementStatus;
use App\Modules\Settlement\Domain\TransitionContext;
use App\Modules\Settlement\Events\NettingAccepted;
use App\Modules\Settlement\Events\NettingExecuted;
use App\Modules\Settlement\Events\NettingProposed;
use App\Modules\Settlement\Events\NettingRejected;
use App\Modules\Settlement\Infrastructure\Models\NettingBatchModel;
use App\Modules\Settlement\Infrastructure\Models\NettingPositionModel;
use App\Modules\Settlement\Infrastructure\Models\NettingSettlementModel;
use App\Modules\Settlement\Infrastructure\Models\SettlementModel;
use App\Modules\Shared\Exceptions\InvalidStateTransitionException;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use App\Modules\Shared\Support\IntMath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * End-of-day netting — docs/03-domain/05-settlement.md §5.6, formulas F12/F13.
 *
 * Ten obligations between five members are twenty operations. Netted
 * bilaterally they become five transfers; netted multilaterally each member has
 * exactly one flow, through the clearing account. That reduction is the point.
 *
 * The process, straight from §5.6:
 *
 *   17:30  market closes
 *   17:35  collect unsettled obligations → compute net positions
 *          → create the batch in PROPOSED → send it to every participant
 *   18:00  acceptance deadline
 *          everyone accepted → execute, one big transaction_group
 *          anyone rejected  → cancel the batch, fall back to gross settlement
 *
 * ADR-009 is the rule this class exists to enforce: **netting is never
 * automatic**. A proposal is an offer. Silence is not consent, a deadline is
 * not consent, and one refusal kills the batch for everybody — invariant N6.
 * That is why execute() re-checks the acceptances against the database instead
 * of trusting the batch status.
 *
 * Multilateral execution routes every flow through SYSTEM/CLEARING, which by
 * N1 nets to exactly zero across the group; execute() asserts that residual
 * rather than assuming it (worked example 3: "حساب CLEARING در پایان صفر است").
 * The credit-risk cost of that account is the reason §5.6 confines multilateral
 * netting to phase 3, conditional on legal sign-off and guarantee capital.
 */
final readonly class NettingService
{
    public function __construct(
        private SettlementStateMachine $stateMachine,
        private LedgerInterface $ledger,
        private NettingCalculator $calculator,
    ) {}

    // ── propose ──────────────────────────────────────────────────────────────

    /**
     * Compute a batch from a set of settlements and offer it to the
     * participants. Nothing moves; the settlements go to NETTING_QUEUE.
     *
     * @param  list<int>  $settlementIds
     */
    public function propose(
        array $settlementIds,
        NettingType $type = NettingType::MULTILATERAL,
        AssetType $asset = AssetType::GOLD,
        ?CarbonImmutable $batchDate = null,
        ?CarbonImmutable $acceptDeadlineAt = null,
        ?int $proposedByUserId = null,
    ): NettingBatchModel {
        $batch = DB::transaction(function () use (
            $settlementIds,
            $type,
            $asset,
            $batchDate,
            $acceptDeadlineAt,
            $proposedByUserId,
        ): NettingBatchModel {
            sort($settlementIds);

            $settlements = $this->lockSettlements($settlementIds);
            $obligations = $this->obligationsFrom($settlements, $asset);

            $positions = $this->calculator->netPositions($obligations);
            $this->calculator->assertBalanced($positions, $asset->value);

            if (count($positions) < 2) {
                throw new OperationNotPermittedException('A netting batch needs at least two participants');
            }

            $nets = $this->calculator->bilateralNets($obligations);

            $netTransferCount = $type === NettingType::MULTILATERAL
                ? $this->calculator->transferCount($positions)
                : $this->calculator->transferCount($nets);

            $netVolume = $type === NettingType::MULTILATERAL
                ? $this->calculator->netVolume($positions)
                : $this->calculator->bilateralVolume($nets);

            $batch = NettingBatchModel::query()->create([
                'batch_code' => 'NB-PENDING-'.bin2hex(random_bytes(4)),
                'batch_date' => ($batchDate ?? CarbonImmutable::now())->toDateString(),
                'netting_type' => $type->value,
                'asset_type' => $asset->value,
                'status' => NettingBatchStatus::PROPOSED->value,
                'participant_count' => count($positions),
                'gross_transfer_count' => count($obligations),
                'net_transfer_count' => $netTransferCount,
                'gross_volume' => $this->calculator->grossVolume($obligations),
                'net_volume' => $netVolume,
                'proposed_at' => now(),
                'accept_deadline_at' => $acceptDeadlineAt,
                'proposed_by_user_id' => $proposedByUserId,
            ]);

            $batch->batch_code = sprintf('NB-%08d', (int) $batch->id);
            $batch->save();

            foreach ($positions as $position) {
                NettingPositionModel::query()->create([
                    'batch_id' => $batch->id,
                    'organization_id' => $position->organizationId,
                    'gross_in' => $position->grossIn,
                    'gross_out' => $position->grossOut,
                    'net_position' => $position->net,
                    'obligation_count' => $position->obligationCount,
                ]);
            }

            foreach ($obligations as $obligation) {
                NettingSettlementModel::query()->create([
                    'batch_id' => $batch->id,
                    'settlement_id' => $obligation->settlementId,
                    'from_organization_id' => $obligation->fromOrganizationId,
                    'to_organization_id' => $obligation->toOrganizationId,
                    'amount' => $obligation->amount,
                ]);
            }

            foreach ($settlements as $settlement) {
                $this->queueForNetting($settlement, (int) $batch->id);
            }

            return $batch->refresh();
        }, attempts: 3);

        event(new NettingProposed(
            batchId: (int) $batch->id,
            batchCode: (string) $batch->batch_code,
            batchDate: (string) $batch->batch_date?->toDateString(),
            nettingType: $batch->netting_type->value,
            assetType: $batch->asset_type->value,
            participantOrganizationIds: $this->participantIds($batch),
            settlementIds: $this->settlementIds($batch),
            grossTransferCount: $batch->gross_transfer_count,
            netTransferCount: $batch->net_transfer_count,
            grossVolume: $batch->gross_volume,
            netVolume: $batch->net_volume,
            acceptDeadlineAt: $batch->accept_deadline_at?->toIso8601String(),
            occurredAt: now()->toIso8601String(),
        ));

        return $batch;
    }

    // ── accept / reject ──────────────────────────────────────────────────────

    /** One participant accepts. The batch moves to ACCEPTING on the first one. */
    public function accept(int $batchId, int $organizationId, ?int $userId = null): NettingBatchModel
    {
        /** @var array{batch: NettingBatchModel, position: NettingPositionModel, accepted: int, total: int} $result */
        $result = DB::transaction(function () use ($batchId, $organizationId, $userId): array {
            $batch = $this->lockBatch($batchId);

            if (! $batch->status->acceptsResponses()) {
                throw new OperationNotPermittedException(
                    'Batch '.$batch->batch_code.' is '.$batch->status->value.' and no longer accepts responses'
                );
            }

            $position = $this->lockPosition($batchId, $organizationId);

            if ($position->hasAnswered()) {
                throw new OperationNotPermittedException('This participant has already answered');
            }

            $position->accepted_at = now();
            $position->accepted_by_user_id = $userId;
            $position->save();

            if ($batch->status === NettingBatchStatus::PROPOSED) {
                $this->moveBatch($batch, NettingBatchStatus::ACCEPTING);
            }

            return [
                'batch' => $batch->refresh(),
                'position' => $position,
                'accepted' => $this->acceptedCount($batchId),
                'total' => $batch->participant_count,
            ];
        }, attempts: 3);

        event(new NettingAccepted(
            batchId: $batchId,
            batchCode: (string) $result['batch']->batch_code,
            organizationId: $organizationId,
            netPosition: $result['position']->net_position,
            acceptedByUserId: $userId,
            acceptedCount: $result['accepted'],
            participantCount: $result['total'],
            allAccepted: $result['accepted'] === $result['total'],
            occurredAt: now()->toIso8601String(),
        ));

        return $result['batch'];
    }

    /**
     * One participant refuses — §5.6, "حداقل یکی رد کرد ► ابطال کل دسته".
     *
     * The whole batch dies and every settlement in it falls back to ordinary
     * gross settlement. There is no partial netting: the positions were
     * computed over the whole set, so dropping one participant would change
     * everybody else's number.
     */
    public function reject(
        int $batchId,
        int $organizationId,
        string $reason,
        ?int $userId = null,
    ): NettingBatchModel {
        /** @var array{batch: NettingBatchModel, settlement_ids: list<int>} $result */
        $result = DB::transaction(function () use ($batchId, $organizationId, $reason, $userId): array {
            $batch = $this->lockBatch($batchId);

            if (! $batch->status->acceptsResponses()) {
                throw new OperationNotPermittedException(
                    'Batch '.$batch->batch_code.' is '.$batch->status->value.' and no longer accepts responses'
                );
            }

            $position = $this->lockPosition($batchId, $organizationId);

            if ($position->hasAnswered()) {
                throw new OperationNotPermittedException('This participant has already answered');
            }

            $position->rejected_at = now();
            $position->rejected_by_user_id = $userId;
            $position->rejection_reason = $reason;
            $position->save();

            $settlementIds = $this->cancelBatch(
                $batch,
                sprintf('Rejected by organisation %d: %s', $organizationId, $reason),
            );

            return ['batch' => $batch->refresh(), 'settlement_ids' => $settlementIds];
        }, attempts: 3);

        event(new NettingRejected(
            batchId: $batchId,
            batchCode: (string) $result['batch']->batch_code,
            organizationId: $organizationId,
            rejectedByUserId: $userId,
            reason: $reason,
            settlementIds: $result['settlement_ids'],
            occurredAt: now()->toIso8601String(),
        ));

        return $result['batch'];
    }

    /**
     * The 18:00 deadline passed with someone still silent. Same outcome as a
     * rejection: silence is not consent (ADR-009).
     */
    public function cancelExpired(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        $ids = NettingBatchModel::query()
            ->whereIn('status', [NettingBatchStatus::PROPOSED->value, NettingBatchStatus::ACCEPTING->value])
            ->whereNotNull('accept_deadline_at')
            ->where('accept_deadline_at', '<=', $now)
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            $this->cancel((int) $id, 'Acceptance deadline passed without unanimous approval');
        }

        return $ids->count();
    }

    /** Cancel a batch outright; settlements fall back to gross settlement. */
    public function cancel(int $batchId, string $reason): NettingBatchModel
    {
        return DB::transaction(function () use ($batchId, $reason): NettingBatchModel {
            $batch = $this->lockBatch($batchId);
            $this->cancelBatch($batch, $reason);

            return $batch->refresh();
        }, attempts: 3);
    }

    // ── execute ──────────────────────────────────────────────────────────────

    /**
     * Post the whole batch as one balanced transaction_group and settle every
     * obligation in it.
     *
     * @throws NettingNotAcceptedException when anybody has not accepted
     */
    public function execute(int $batchId): NettingBatchModel
    {
        /** @var array{batch: NettingBatchModel, group: string, settlement_ids: list<int>, residual: int} $result */
        $result = DB::transaction(function () use ($batchId): array {
            $batch = $this->lockBatch($batchId);

            if ($batch->status !== NettingBatchStatus::ACCEPTING
                && $batch->status !== NettingBatchStatus::FAILED) {
                throw new InvalidStateTransitionException(
                    'NettingBatch',
                    $batch->status->value,
                    NettingBatchStatus::EXECUTING->value,
                );
            }

            // N6 / ADR-009, checked against the rows and not the status.
            $pending = $this->pendingParticipants($batchId);

            if ($pending !== []) {
                throw new NettingNotAcceptedException($batchId, $pending);
            }

            $this->moveBatch($batch, NettingBatchStatus::EXECUTING);

            $positions = $this->positionsOf($batchId);
            $obligations = $this->obligationsOf($batchId);

            // N1 again: the numbers were right when proposed, and the ledger is
            // about to be written, so they are re-checked here too.
            $this->calculator->assertBalanced($positions, $batch->asset_type->value);

            $group = $batch->netting_type === NettingType::MULTILATERAL
                ? $this->postMultilateral($batch, $positions)
                : $this->postBilateral($batch, $positions, $this->calculator->bilateralNets($obligations));

            $settlementIds = $this->settlementIds($batch);

            foreach ($settlementIds as $settlementId) {
                $this->stateMachine->transition(
                    $settlementId,
                    SettlementStatus::SETTLED,
                    (new TransitionContext(
                        reason: 'Netted in batch '.$batch->batch_code,
                        metadata: ['netting_batch_id' => (int) $batch->id],
                    ))->withLedgerAlreadyPosted($group),
                );
            }

            $residual = $this->clearingBalance($batch->asset_type);

            $batch->transaction_group = $group;
            $batch->executed_at = now();
            $batch->save();

            $this->moveBatch($batch, NettingBatchStatus::EXECUTED);

            return [
                'batch' => $batch->refresh(),
                'group' => $group,
                'settlement_ids' => $settlementIds,
                'residual' => $residual,
            ];
        }, attempts: 3);

        $batch = $result['batch'];

        event(new NettingExecuted(
            batchId: (int) $batch->id,
            batchCode: (string) $batch->batch_code,
            nettingType: $batch->netting_type->value,
            assetType: $batch->asset_type->value,
            settlementIds: $result['settlement_ids'],
            participantOrganizationIds: $this->participantIds($batch),
            grossTransferCount: $batch->gross_transfer_count,
            netTransferCount: $batch->net_transfer_count,
            grossVolume: $batch->gross_volume,
            netVolume: $batch->net_volume,
            clearingResidual: $result['residual'],
            transactionGroup: $result['group'],
            occurredAt: now()->toIso8601String(),
        ));

        return $batch;
    }

    /**
     * Multilateral posting — worked example 3, transaction group g8.
     *
     * Per participant, in ascending organisation id:
     *
     *   debtor   IN_SETTLEMENT −gross_out, AVAILABLE +gross_in, CLEARING +|net|
     *   creditor CLEARING −net, AVAILABLE +net, IN_SETTLEMENT −gross_out,
     *            AVAILABLE +gross_out
     *   flat     IN_SETTLEMENT −gross_out, AVAILABLE +gross_in
     *
     * Read across all participants that is: everybody's locked gross_out leaves
     * IN_SETTLEMENT, everybody's gross_in arrives in AVAILABLE, and CLEARING
     * absorbs the difference — which sums to −Σ net = 0. The split of the
     * creditors' credit into two legs follows the document's own layout so the
     * entry table can be compared line for line.
     *
     * @param  list<NetPosition>  $positions
     */
    private function postMultilateral(NettingBatchModel $batch, array $positions): string
    {
        $asset = $batch->asset_type;
        $ref = LedgerReference::of('netting_batch', (int) $batch->id);

        return $this->ledger->postGroup(function (GroupWriter $writer) use ($positions, $asset, $ref): void {
            // A zero-amount ledger entry is rejected outright, so every leg
            // below is guarded: a participant who only receives has no
            // gross_out to unlock, and one who only pays has no gross_in.
            foreach ($positions as $position) {
                $org = $position->organizationId;

                if ($position->isCreditor()) {
                    $writer->postSystem(
                        SystemAccountCode::CLEARING, $asset, -$position->net,
                        EntryType::NETTING_SETTLE, $ref,
                    );
                    $writer->post(
                        $org, $asset, Bucket::AVAILABLE, $position->net,
                        EntryType::NETTING_SETTLE, $ref,
                    );

                    if ($position->grossOut > 0) {
                        $writer->post(
                            $org, $asset, Bucket::IN_SETTLEMENT, -$position->grossOut,
                            EntryType::NETTING_SETTLE, $ref,
                        );
                        $writer->post(
                            $org, $asset, Bucket::AVAILABLE, $position->grossOut,
                            EntryType::NETTING_SETTLE, $ref,
                        );
                    }

                    continue;
                }

                if ($position->grossOut > 0) {
                    $writer->post(
                        $org, $asset, Bucket::IN_SETTLEMENT, -$position->grossOut,
                        EntryType::NETTING_SETTLE, $ref,
                    );
                }

                if ($position->grossIn > 0) {
                    $writer->post(
                        $org, $asset, Bucket::AVAILABLE, $position->grossIn,
                        EntryType::NETTING_SETTLE, $ref,
                    );
                }

                if ($position->isDebtor()) {
                    $writer->postSystem(
                        SystemAccountCode::CLEARING, $asset, $position->magnitude(),
                        EntryType::NETTING_SETTLE, $ref,
                    );
                }
            }
        })->value;
    }

    /**
     * Bilateral posting — F12, no clearing account involved.
     *
     * Everybody's locked gross_out comes back to AVAILABLE first, then each
     * surviving pair net moves directly between the two members. Pairs that
     * cancel out entirely move nothing, which is exactly the "تسویه کامل، بدون
     * انتقال" case of F12.
     *
     * @param  list<NetPosition>  $positions
     * @param  list<BilateralNet>  $nets
     */
    private function postBilateral(NettingBatchModel $batch, array $positions, array $nets): string
    {
        $asset = $batch->asset_type;
        $ref = LedgerReference::of('netting_batch', (int) $batch->id);

        return $this->ledger->postGroup(function (GroupWriter $writer) use ($positions, $nets, $asset, $ref): void {
            foreach ($positions as $position) {
                if ($position->grossOut === 0) {
                    continue;
                }

                $writer->post(
                    $position->organizationId, $asset, Bucket::IN_SETTLEMENT, -$position->grossOut,
                    EntryType::NETTING_SETTLE, $ref,
                );
                $writer->post(
                    $position->organizationId, $asset, Bucket::AVAILABLE, $position->grossOut,
                    EntryType::NETTING_SETTLE, $ref,
                );
            }

            foreach ($nets as $net) {
                if ($net->isSettledOut()) {
                    continue;
                }

                $writer->post(
                    (int) $net->payerOrganizationId(), $asset, Bucket::AVAILABLE, -$net->amount(),
                    EntryType::NETTING_SETTLE, $ref,
                );
                $writer->post(
                    (int) $net->receiverOrganizationId(), $asset, Bucket::AVAILABLE, $net->amount(),
                    EntryType::NETTING_SETTLE, $ref,
                );
            }
        })->value;
    }

    // ── reading ──────────────────────────────────────────────────────────────

    /** @return list<NetPosition> */
    public function positionsOf(int $batchId): array
    {
        return NettingPositionModel::query()
            ->where('batch_id', $batchId)
            ->orderBy('organization_id')
            ->get()
            ->map(static fn (NettingPositionModel $p): NetPosition => $p->toNetPosition())
            ->all();
    }

    /** @return list<Obligation> */
    public function obligationsOf(int $batchId): array
    {
        return NettingSettlementModel::query()
            ->where('batch_id', $batchId)
            ->orderBy('settlement_id')
            ->get()
            ->map(static fn (NettingSettlementModel $r): Obligation => $r->toObligation())
            ->all();
    }

    /** @return list<int> */
    public function pendingParticipants(int $batchId): array
    {
        return NettingPositionModel::query()
            ->where('batch_id', $batchId)
            ->whereNull('accepted_at')
            ->orderBy('organization_id')
            ->pluck('organization_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * How much of the asset the clearing account is holding. Zero is the only
     * acceptable answer once a multilateral batch has executed.
     */
    public function clearingBalance(AssetType $asset): int
    {
        return (int) DB::table('ledger_accounts as a')
            ->join('ledger_balances as b', 'b.account_id', '=', 'a.id')
            ->where('a.system_account_code', SystemAccountCode::CLEARING->value)
            ->where('a.asset_type', $asset->value)
            ->sum('b.balance');
    }

    // ── internals ────────────────────────────────────────────────────────────

    /**
     * @param  list<int>  $settlementIds
     * @return list<SettlementModel> ascending id — AGENT_BRIEF rule 4
     */
    private function lockSettlements(array $settlementIds): array
    {
        $settlements = [];

        foreach ($settlementIds as $id) {
            $settlements[] = $this->stateMachine->lock((int) $id);
        }

        return $settlements;
    }

    /**
     * Build the directed obligations a batch nets, rejecting any settlement
     * already committed to another live batch (invariant N5).
     *
     * @param  list<SettlementModel>  $settlements
     * @return list<Obligation>
     */
    private function obligationsFrom(array $settlements, AssetType $asset): array
    {
        $obligations = [];

        foreach ($settlements as $settlement) {
            $this->assertNotAlreadyNetted($settlement);

            $obligations[] = $asset === AssetType::GOLD
                ? new Obligation(
                    settlementId: (int) $settlement->id,
                    fromOrganizationId: $settlement->gold_deliverer_org_id,
                    toOrganizationId: $settlement->gold_receiver_org_id,
                    amount: $settlement->fine_weight_mg,
                )
                : new Obligation(
                    settlementId: (int) $settlement->id,
                    fromOrganizationId: $settlement->cash_payer_org_id,
                    toOrganizationId: $settlement->cash_receiver_org_id,
                    amount: $settlement->totalCashDue()->amount,
                );
        }

        return $obligations;
    }

    private function assertNotAlreadyNetted(SettlementModel $settlement): void
    {
        $blocking = array_map(
            static fn (NettingBatchStatus $s): string => $s->value,
            array_filter(
                NettingBatchStatus::cases(),
                static fn (NettingBatchStatus $s): bool => $s->blocksReNetting(),
            ),
        );

        $existing = DB::table('netting_settlements as ns')
            ->join('netting_batches as b', 'b.id', '=', 'ns.batch_id')
            ->where('ns.settlement_id', $settlement->id)
            ->whereIn('b.status', $blocking)
            ->value('ns.batch_id');

        if ($existing !== null) {
            throw new SettlementAlreadyNettedException((int) $settlement->id, (int) $existing);
        }
    }

    /**
     * ASSETS_LOCKED → NETTING_QUEUE, the alternative route of §5.2. A
     * settlement already sitting in PAYMENT_PENDING has no edge back, so it is
     * refused here rather than silently skipped.
     */
    private function queueForNetting(SettlementModel $settlement, int $batchId): void
    {
        if ($settlement->status !== SettlementStatus::NETTING_QUEUE) {
            $this->stateMachine->transition(
                (int) $settlement->id,
                SettlementStatus::NETTING_QUEUE,
                TransitionContext::system('Queued for netting batch '.$batchId),
            );
        }

        $fresh = $this->stateMachine->lock((int) $settlement->id);
        $fresh->netting_batch_id = $batchId;
        $fresh->save();
    }

    /**
     * Kill a batch and put its settlements back on the gross path
     * (NETTING_QUEUE → PAYMENT_PENDING).
     *
     * @return list<int> the settlement ids released
     */
    private function cancelBatch(NettingBatchModel $batch, string $reason): array
    {
        $settlementIds = $this->settlementIds($batch);

        foreach ($settlementIds as $settlementId) {
            $settlement = $this->stateMachine->lock($settlementId);

            if ($settlement->status === SettlementStatus::NETTING_QUEUE) {
                $this->stateMachine->transition(
                    $settlementId,
                    SettlementStatus::PAYMENT_PENDING,
                    TransitionContext::system('Netting batch cancelled — falling back to gross settlement'),
                );
            }

            $fresh = $this->stateMachine->lock($settlementId);
            $fresh->netting_batch_id = null;
            $fresh->save();
        }

        $batch->cancelled_at = now();
        $batch->cancelled_reason = $reason;
        $batch->save();

        $this->moveBatch($batch, NettingBatchStatus::CANCELLED);

        return $settlementIds;
    }

    private function moveBatch(NettingBatchModel $batch, NettingBatchStatus $target): void
    {
        if (! $batch->status->canTransitionTo($target)) {
            throw new InvalidStateTransitionException(
                'NettingBatch',
                $batch->status->value,
                $target->value,
            );
        }

        $batch->status = $target;
        $batch->save();
    }

    private function lockBatch(int $batchId): NettingBatchModel
    {
        $batch = NettingBatchModel::query()->whereKey($batchId)->lockForUpdate()->first();

        if (! $batch instanceof NettingBatchModel) {
            throw new OperationNotPermittedException('Netting batch '.$batchId.' does not exist');
        }

        return $batch;
    }

    private function lockPosition(int $batchId, int $organizationId): NettingPositionModel
    {
        $position = NettingPositionModel::query()
            ->where('batch_id', $batchId)
            ->where('organization_id', $organizationId)
            ->lockForUpdate()
            ->first();

        if (! $position instanceof NettingPositionModel) {
            throw new OperationNotPermittedException(
                'Organisation '.$organizationId.' is not a participant in this batch'
            );
        }

        return $position;
    }

    private function acceptedCount(int $batchId): int
    {
        return NettingPositionModel::query()
            ->where('batch_id', $batchId)
            ->whereNotNull('accepted_at')
            ->count();
    }

    /** @return list<int> */
    private function participantIds(NettingBatchModel $batch): array
    {
        return NettingPositionModel::query()
            ->where('batch_id', $batch->id)
            ->orderBy('organization_id')
            ->pluck('organization_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /** @return list<int> */
    private function settlementIds(NettingBatchModel $batch): array
    {
        return NettingSettlementModel::query()
            ->where('batch_id', $batch->id)
            ->orderBy('settlement_id')
            ->pluck('settlement_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * How many operations the batch saved, for the dashboard line of §5.9
     * ("۳ تعهد ◄ ۱ انتقال").
     */
    public function reduction(NettingBatchModel $batch): int
    {
        return IntMath::sub($batch->gross_transfer_count, $batch->net_transfer_count);
    }
}
