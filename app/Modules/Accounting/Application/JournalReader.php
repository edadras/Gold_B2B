<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\AccountCode;
use App\Modules\Accounting\Domain\EntryStatus;
use App\Modules\Accounting\Infrastructure\Models\JournalEntryModel;
use App\Modules\Accounting\Infrastructure\Models\JournalLineModel;
use App\Modules\Shared\Support\IntMath;

/**
 * Reading the journal back — `GET /accounting/journal` and
 * `GET /accounting/export` (docs/05-api/02-endpoints.md §2.13).
 *
 * The module could write vouchers (JournalPoster) and aggregate them
 * (TrialBalanceService) but had nothing that listed them, so the two endpoints
 * had no service to call and the controller would have had to run Eloquent
 * itself. This is that service.
 *
 * READ-ONLY BY CONSTRUCTION. Nothing here updates or deletes: §9.7 forbids
 * deleting a voucher outright («حذف — ممنوع؛ فقط REVERSED»), and a reader that
 * could write would be one refactor away from breaking that.
 *
 * DRAFT and REVERSED entries are INCLUDED in the journal listing but excluded
 * from its control totals. A member's accountant needs to see that a voucher
 * was reversed — that is the audit trail — while the totals must agree with the
 * trial balance, which counts POSTED only.
 *
 * No floating-point arithmetic anywhere: Accounting is a FINANCIAL_PATH, all
 * money is integer rial and all weight integer fine milligrams.
 */
final readonly class JournalReader
{
    /** @var int hard ceiling on one page of the journal */
    private const MAX_ENTRIES = 1_000;

    /**
     * Vouchers and their lines for one organisation over an inclusive date range.
     *
     * @return array{
     *     entries: array<int, JournalEntryModel>,
     *     totals: array<string, int>
     * }
     */
    public function entries(int $organizationId, string $fromDate, string $toDate): array
    {
        /** @var array<int, JournalEntryModel> $entries */
        $entries = JournalEntryModel::query()
            ->with(['lines' => static fn ($query) => $query->orderBy('line_no')])
            ->where('organization_id', $organizationId)
            ->whereBetween('entry_date', [$fromDate, $toDate])
            ->orderBy('entry_date')
            ->orderBy('id')
            ->limit(self::MAX_ENTRIES)
            ->get()
            ->all();

        return ['entries' => $entries, 'totals' => $this->totalsOf($entries)];
    }

    /**
     * The same period flattened to one row per journal LINE, which is the shape
     * every external accounting package imports.
     *
     * Denormalised on purpose: each row repeats its voucher's number, date and
     * description so a single sheet is self-contained once it leaves here. The
     * account caption travels with the code because a code alone is unreadable
     * to the person checking the import, and only POSTED vouchers are exported
     * — a DRAFT has not happened and a REVERSED one is already cancelled by its
     * mirror, which is itself POSTED and therefore already in the file.
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, int>}
     */
    public function exportRows(int $organizationId, string $fromDate, string $toDate): array
    {
        $lines = JournalLineModel::query()
            ->join('journal_entries as e', 'e.id', '=', 'journal_lines.journal_entry_id')
            ->where('e.organization_id', $organizationId)
            ->where('e.status', EntryStatus::POSTED->value)
            ->whereBetween('e.entry_date', [$fromDate, $toDate])
            ->orderBy('e.entry_date')
            ->orderBy('e.id')
            ->orderBy('journal_lines.line_no')
            ->limit(self::MAX_ENTRIES * 20)
            ->get([
                'journal_lines.*',
                'e.voucher_no as voucher_no',
                'e.entry_date as entry_date',
                'e.description as entry_description',
                'e.source_type as source_type',
                'e.source_id as source_id',
                'e.status as entry_status',
            ]);

        $rows = [];
        $debitRial = [];
        $creditRial = [];
        $debitGold = [];
        $creditGold = [];

        foreach ($lines as $line) {
            $account = AccountCode::tryFromCode((string) $line->account_code);

            $rows[] = [
                'voucher_no' => (string) $line->voucher_no,
                'entry_date' => (string) $line->entry_date,
                'entry_description' => (string) $line->entry_description,
                'source_type' => (string) $line->source_type,
                'source_id' => (int) $line->source_id,
                'line_no' => (int) $line->line_no,
                'line_set' => (string) $line->line_set,
                'account_code' => (string) $line->account_code,
                'account_name' => $account?->label(),
                'account_type' => $account?->type()->value,
                'counterparty_org_id' => $line->counterparty_org_id === null
                    ? null
                    : (int) $line->counterparty_org_id,
                'debit_rial' => (int) $line->debit_rial,
                'credit_rial' => (int) $line->credit_rial,
                'debit_fine_mg' => (int) $line->debit_fine_mg,
                'credit_fine_mg' => (int) $line->credit_fine_mg,
                'description' => $line->description,
            ];

            $debitRial[] = (int) $line->debit_rial;
            $creditRial[] = (int) $line->credit_rial;
            $debitGold[] = (int) $line->debit_fine_mg;
            $creditGold[] = (int) $line->credit_fine_mg;
        }

        $totalDebitRial = IntMath::sum($debitRial);
        $totalCreditRial = IntMath::sum($creditRial);
        $totalDebitGold = IntMath::sum($debitGold);
        $totalCreditGold = IntMath::sum($creditGold);

        return [
            'rows' => $rows,
            'totals' => [
                'line_count' => count($rows),
                'total_debit_rial' => $totalDebitRial,
                'total_credit_rial' => $totalCreditRial,
                'total_debit_fine_mg' => $totalDebitGold,
                'total_credit_fine_mg' => $totalCreditGold,
                'rial_difference' => IntMath::sub($totalDebitRial, $totalCreditRial),
                'fine_mg_difference' => IntMath::sub($totalDebitGold, $totalCreditGold),
            ],
        ];
    }

    /**
     * Control totals over POSTED entries only, so they agree with the trial
     * balance built from the same period.
     *
     * @param  array<int, JournalEntryModel>  $entries
     * @return array<string, int>
     */
    private function totalsOf(array $entries): array
    {
        $rial = [];
        $fineMg = [];
        $posted = 0;

        foreach ($entries as $entry) {
            if ($entry->status !== EntryStatus::POSTED->value) {
                continue;
            }

            $posted++;
            $rial[] = (int) $entry->total_rial;
            $fineMg[] = (int) $entry->total_fine_mg;
        }

        return [
            'entry_count' => count($entries),
            'posted_count' => $posted,
            'total_rial' => IntMath::sum($rial),
            'total_fine_mg' => IntMath::sum($fineMg),
        ];
    }
}
