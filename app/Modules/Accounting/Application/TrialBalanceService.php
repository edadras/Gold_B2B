<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Contracts\TrialBalance;
use App\Modules\Accounting\Contracts\TrialBalanceRow;
use App\Modules\Accounting\Domain\AccountCode;
use App\Modules\Accounting\Domain\EntryStatus;
use App\Modules\Shared\Support\IntMath;
use Illuminate\Support\Facades\DB;

/**
 * The trial balance report of §9.4, both columns.
 *
 * Only POSTED vouchers count: DRAFT has not happened yet and REVERSED has been
 * cancelled by its mirror voucher, which is itself POSTED and therefore already
 * in the numbers.
 */
final class TrialBalanceService
{
    public function build(int $organizationId, string $fromDate, string $toDate): TrialBalance
    {
        $aggregates = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.organization_id', $organizationId)
            ->where('e.status', EntryStatus::POSTED->value)
            ->whereBetween('e.entry_date', [$fromDate, $toDate])
            ->groupBy('l.account_code')
            ->orderBy('l.account_code')
            ->select([
                'l.account_code',
                DB::raw('SUM(l.debit_rial) as debit_rial'),
                DB::raw('SUM(l.credit_rial) as credit_rial'),
                DB::raw('SUM(l.debit_fine_mg) as debit_fine_mg'),
                DB::raw('SUM(l.credit_fine_mg) as credit_fine_mg'),
            ])
            ->get();

        $rows = [];
        $debitRial = [];
        $creditRial = [];
        $debitGold = [];
        $creditGold = [];

        foreach ($aggregates as $aggregate) {
            $code = AccountCode::tryFromCode((string) $aggregate->account_code);

            if ($code === null) {
                // An unknown code means someone wrote outside the chart. Skip it
                // in the rows but keep it in the totals so the report still
                // fails to foot and the discrepancy stays visible.
                $debitRial[] = (int) $aggregate->debit_rial;
                $creditRial[] = (int) $aggregate->credit_rial;
                $debitGold[] = (int) $aggregate->debit_fine_mg;
                $creditGold[] = (int) $aggregate->credit_fine_mg;

                continue;
            }

            $row = new TrialBalanceRow(
                account: $code,
                debitRial: (int) $aggregate->debit_rial,
                creditRial: (int) $aggregate->credit_rial,
                debitFineMg: (int) $aggregate->debit_fine_mg,
                creditFineMg: (int) $aggregate->credit_fine_mg,
            );

            if ($row->isEmpty()) {
                continue;
            }

            $rows[] = $row;
            $debitRial[] = $row->debitRial;
            $creditRial[] = $row->creditRial;
            $debitGold[] = $row->debitFineMg;
            $creditGold[] = $row->creditFineMg;
        }

        return new TrialBalance(
            organizationId: $organizationId,
            fromDate: $fromDate,
            toDate: $toDate,
            rows: $rows,
            totalDebitRial: IntMath::sum($debitRial),
            totalCreditRial: IntMath::sum($creditRial),
            totalDebitFineMg: IntMath::sum($debitGold),
            totalCreditFineMg: IntMath::sum($creditGold),
        );
    }

    /**
     * Net movement on a single account over a range, in rial.
     *
     * Positive means the account moved to the debit side.
     */
    public function netRialOn(int $organizationId, AccountCode $account, string $fromDate, string $toDate): int
    {
        $row = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.organization_id', $organizationId)
            ->where('e.status', EntryStatus::POSTED->value)
            ->where('l.account_code', $account->value)
            ->whereBetween('e.entry_date', [$fromDate, $toDate])
            ->selectRaw('COALESCE(SUM(l.debit_rial),0) as d, COALESCE(SUM(l.credit_rial),0) as c')
            ->first();

        return IntMath::sub((int) ($row->d ?? 0), (int) ($row->c ?? 0));
    }

    /** Net movement on a single account over a range, in fine milligrams. */
    public function netFineMgOn(int $organizationId, AccountCode $account, string $fromDate, string $toDate): int
    {
        $row = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.organization_id', $organizationId)
            ->where('e.status', EntryStatus::POSTED->value)
            ->where('l.account_code', $account->value)
            ->whereBetween('e.entry_date', [$fromDate, $toDate])
            ->selectRaw('COALESCE(SUM(l.debit_fine_mg),0) as d, COALESCE(SUM(l.credit_fine_mg),0) as c')
            ->first();

        return IntMath::sub((int) ($row->d ?? 0), (int) ($row->c ?? 0));
    }
}
