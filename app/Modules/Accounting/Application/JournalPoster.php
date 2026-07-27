<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Contracts\JournalPosterInterface;
use App\Modules\Accounting\Contracts\PostingResult;
use App\Modules\Accounting\Contracts\VoucherDraft;
use App\Modules\Accounting\Contracts\VoucherLine;
use App\Modules\Accounting\Domain\AccountCode;
use App\Modules\Accounting\Domain\EntryStatus;
use App\Modules\Accounting\Domain\LineSet;
use App\Modules\Accounting\Domain\SourceType;
use App\Modules\Accounting\Events\JournalEntryPosted;
use App\Modules\Accounting\Infrastructure\Models\JournalEntryModel;
use App\Modules\Accounting\Infrastructure\Models\JournalLineModel;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use App\Modules\Shared\Exceptions\UnbalancedTransactionException;
use App\Modules\Shared\Support\IntMath;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Writes vouchers into the journal.
 *
 * Three guarantees, in the order they are enforced:
 *
 *  1. **Both columns balance.** §9.2 requires Σ debit_rial = Σ credit_rial AND
 *     Σ debit_fine_mg = Σ credit_fine_mg simultaneously. Checked before any row
 *     is written, and checked again per line set, because a voucher whose two
 *     sets happen to cancel each other out across sets would satisfy the
 *     entry-wide sums while being meaningless.
 *  2. **The period is open.** §9.7 — a closed period takes no new vouchers.
 *  3. **Posting is idempotent.** `uq_source` makes a redelivered event collide;
 *     the collision is caught and the existing voucher returned unchanged. This
 *     matters because §9.7 puts voucher generation on a queue, and queues
 *     redeliver.
 *
 * The event is fired after commit, never inside the transaction.
 */
final class JournalPoster implements JournalPosterInterface
{
    private const MAX_VOUCHER_ATTEMPTS = 5;

    public function __construct(
        private readonly VoucherNumberGenerator $voucherNumbers,
        private readonly PeriodCloseService $periods,
    ) {}

    public function post(VoucherDraft $draft): PostingResult
    {
        return $this->write($draft, allowClosedPeriodRedirect: false);
    }

    public function postCorrection(VoucherDraft $draft): PostingResult
    {
        return $this->write($draft, allowClosedPeriodRedirect: true);
    }

    public function reverse(int $journalEntryId, string $reason, ?int $userId = null): PostingResult
    {
        /** @var ?JournalEntryModel $original */
        $original = JournalEntryModel::query()->with('lines')->find($journalEntryId);

        if ($original === null) {
            throw new OperationNotPermittedException("Journal entry {$journalEntryId} does not exist");
        }

        $status = EntryStatus::from($original->status);

        // Already reversed: hand back the mirror that exists rather than
        // refusing. Reversal is triggered from the same queues as posting, so
        // it has to tolerate redelivery for the same reason posting does.
        if ($status === EntryStatus::REVERSED && $original->reversed_by_id !== null) {
            /** @var ?JournalEntryModel $mirror */
            $mirror = JournalEntryModel::query()->find($original->reversed_by_id);

            if ($mirror !== null) {
                return $this->resultFor($mirror, alreadyExisted: true);
            }
        }

        if (! $status->canTransitionTo(EntryStatus::REVERSED)) {
            throw new OperationNotPermittedException(
                "Journal entry {$journalEntryId} is {$original->status} and cannot be reversed"
            );
        }

        $mirrored = [];

        /** @var JournalLineModel $line */
        foreach ($original->lines as $line) {
            $mirrored[] = new VoucherLine(
                account: AccountCode::from($line->account_code),
                set: LineSet::from($line->line_set),
                debitRial: $line->credit_rial,
                creditRial: $line->debit_rial,
                debitFineMg: $line->credit_fine_mg,
                creditFineMg: $line->debit_fine_mg,
                counterpartyOrgId: $line->counterparty_org_id,
                description: $reason,
            );
        }

        $draft = new VoucherDraft(
            organizationId: $original->organization_id,
            sourceType: SourceType::REVERSAL,
            // Reversal of entry N is itself unique per entry, which keeps the
            // reversal idempotent too: reversing twice returns the same voucher.
            sourceId: $journalEntryId,
            entryDate: $this->periods->postingDateFor(
                $original->organization_id,
                substr((string) $original->entry_date, 0, 10),
            ),
            description: 'ابطال سند '.$original->voucher_no.' — '.$reason,
            lines: $mirrored,
            createdByUserId: $userId,
        );

        $result = $this->write($draft, allowClosedPeriodRedirect: true, reversesId: $journalEntryId);

        if ($result->wasCreated()) {
            JournalEntryModel::query()
                ->whereKey($journalEntryId)
                ->update([
                    'status' => EntryStatus::REVERSED->value,
                    'reversed_by_id' => $result->journalEntryId,
                ]);
        }

        return $result;
    }

    public function existsForSource(int $organizationId, SourceType $sourceType, int $sourceId): bool
    {
        return JournalEntryModel::query()
            ->where('organization_id', $organizationId)
            ->where('source_type', $sourceType->value)
            ->where('source_id', $sourceId)
            ->exists();
    }

    private function write(
        VoucherDraft $draft,
        bool $allowClosedPeriodRedirect,
        ?int $reversesId = null,
    ): PostingResult {
        $draft = $draft->withoutEmptyLines();

        $this->assertBalanced($draft);

        if ($allowClosedPeriodRedirect) {
            $draft = $draft->withEntryDate(
                $this->periods->postingDateFor($draft->organizationId, $draft->entryDate)
            );
        }

        // Cheap path: the voucher is already there, so skip the transaction.
        $existing = $this->findExisting($draft);

        if ($existing !== null) {
            return $this->resultFor($existing, alreadyExisted: true);
        }

        $created = null;

        $result = DB::transaction(function () use ($draft, $reversesId, &$created): PostingResult {
            $periodId = $this->periods->assertOpen($draft->organizationId, $draft->entryDate);

            $entry = $this->insertEntry($draft, $periodId, $reversesId);

            if ($entry === null) {
                // Lost the idempotency race: another worker posted the same
                // source between our check and our insert. Its voucher stands.
                $existing = $this->findExisting($draft);

                if ($existing === null) {
                    throw new OperationNotPermittedException(
                        'Voucher insert collided but no existing voucher could be found'
                    );
                }

                return $this->resultFor($existing, alreadyExisted: true);
            }

            $this->insertLines($entry, $draft);

            $created = $entry;

            return $this->resultFor($entry, alreadyExisted: false);
        });

        // AGENT_BRIEF rule 3: events leave the transaction, never sit inside it.
        if ($created !== null) {
            event(new JournalEntryPosted(
                journalEntryId: (int) $created->id,
                organizationId: $created->organization_id,
                voucherNo: $created->voucher_no,
                entryDate: substr((string) $created->entry_date, 0, 10),
                sourceType: $created->source_type,
                sourceId: $created->source_id,
                totalRial: $created->total_rial,
                totalFineMg: $created->total_fine_mg,
            ));
        }

        return $result;
    }

    /**
     * §9.2's «ثابت الزامی», applied to the whole voucher and to each set.
     *
     * @throws UnbalancedTransactionException
     */
    private function assertBalanced(VoucherDraft $draft): void
    {
        $reference = $draft->sourceType->value.':'.$draft->sourceId;

        $rialDifference = IntMath::sub($draft->totalDebitRial(), $draft->totalCreditRial());

        if ($rialDifference !== 0) {
            throw new UnbalancedTransactionException($reference, 'RIAL', $rialDifference);
        }

        $goldDifference = IntMath::sub($draft->totalDebitFineMg(), $draft->totalCreditFineMg());

        if ($goldDifference !== 0) {
            throw new UnbalancedTransactionException($reference, 'GOLD', $goldDifference);
        }

        // Per-set, so the two sets cannot paper over each other.
        foreach ([LineSet::RIAL, LineSet::GOLD] as $set) {
            $lines = $draft->linesIn($set);

            $debit = IntMath::sum(array_map(
                static fn (VoucherLine $l): int => $set === LineSet::RIAL ? $l->debitRial : $l->debitFineMg,
                $lines,
            ));
            $credit = IntMath::sum(array_map(
                static fn (VoucherLine $l): int => $set === LineSet::RIAL ? $l->creditRial : $l->creditFineMg,
                $lines,
            ));

            if ($debit !== $credit) {
                throw new UnbalancedTransactionException(
                    $reference,
                    $set->value,
                    IntMath::sub($debit, $credit),
                );
            }
        }
    }

    private function findExisting(VoucherDraft $draft): ?JournalEntryModel
    {
        /** @var ?JournalEntryModel $entry */
        $entry = JournalEntryModel::query()
            ->where('organization_id', $draft->organizationId)
            ->where('source_type', $draft->sourceType->value)
            ->where('source_id', $draft->sourceId)
            ->first();

        return $entry;
    }

    /** @return ?JournalEntryModel null when the source key was taken concurrently */
    private function insertEntry(VoucherDraft $draft, ?int $periodId, ?int $reversesId): ?JournalEntryModel
    {
        $attempt = 0;

        while (true) {
            $voucherNo = $attempt === 0
                ? $this->voucherNumbers->next($draft->organizationId, $draft->entryDate)
                : $this->voucherNumbers->nextAfterCollision($draft->organizationId, $draft->entryDate, $attempt);

            try {
                /** @var JournalEntryModel $entry */
                $entry = JournalEntryModel::query()->create([
                    'organization_id' => $draft->organizationId,
                    'voucher_no' => $voucherNo,
                    'entry_date' => $draft->entryDate,
                    'description' => mb_substr($draft->description, 0, 500),
                    'source_type' => $draft->sourceType->value,
                    'source_id' => $draft->sourceId,
                    'status' => EntryStatus::POSTED->value,
                    'posted_at' => now(),
                    'reverses_id' => $reversesId,
                    'total_rial' => $draft->totalDebitRial(),
                    'total_fine_mg' => $draft->totalDebitFineMg(),
                    'accounting_period_id' => $periodId,
                    'created_by_user_id' => $draft->createdByUserId,
                    'created_at' => now(),
                ]);

                return $entry;
            } catch (QueryException $e) {
                if (! $this->isDuplicateKey($e)) {
                    throw $e;
                }

                if ($this->isSourceCollision($e)) {
                    return null;
                }

                if (++$attempt >= self::MAX_VOUCHER_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    private function insertLines(JournalEntryModel $entry, VoucherDraft $draft): void
    {
        $rows = [];
        $lineNo = 0;

        // RIAL first, then GOLD, so a printed voucher reads the way §9.3 draws it.
        foreach ([LineSet::RIAL, LineSet::GOLD] as $set) {
            foreach ($draft->linesIn($set) as $line) {
                $rows[] = [
                    'journal_entry_id' => $entry->id,
                    'organization_id' => $draft->organizationId,
                    'account_code' => $line->account->value,
                    'line_set' => $line->set->value,
                    'counterparty_org_id' => $line->counterpartyOrgId,
                    'debit_rial' => $line->debitRial,
                    'credit_rial' => $line->creditRial,
                    'debit_fine_mg' => $line->debitFineMg,
                    'credit_fine_mg' => $line->creditFineMg,
                    'description' => $line->description === null
                        ? null
                        : mb_substr($line->description, 0, 500),
                    'line_no' => ++$lineNo,
                ];
            }
        }

        JournalLineModel::query()->insert($rows);
    }

    private function resultFor(JournalEntryModel $entry, bool $alreadyExisted): PostingResult
    {
        return new PostingResult(
            journalEntryId: (int) $entry->id,
            voucherNo: $entry->voucher_no,
            alreadyExisted: $alreadyExisted,
            lineCount: (int) JournalLineModel::query()->where('journal_entry_id', $entry->id)->count(),
        );
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }

    private function isSourceCollision(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'uq_source');
    }
}
