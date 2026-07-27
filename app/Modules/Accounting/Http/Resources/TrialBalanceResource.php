<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Resources;

use App\Modules\Accounting\Contracts\TrialBalance;
use App\Modules\Accounting\Contracts\TrialBalanceRow;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * `GET /reports/trial-balance` — §9.4, both columns.
 *
 * `is_balanced` and the two differences are reported, never assumed. A trial
 * balance that does not foot is the single most important signal this module
 * can produce, and a client must be able to render the red banner without
 * re-deriving it from the rows.
 *
 * Gold and rial are separate columns throughout: §9.3 keeps a voucher's RIAL
 * and GOLD line sets independently balanced, so the report has two totals to
 * check, not one.
 *
 * @mixin TrialBalance
 */
final class TrialBalanceResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TrialBalance $balance */
        $balance = $this->resource;

        return [
            'range' => ['from' => $balance->fromDate, 'to' => $balance->toDate],
            'rows' => array_map(
                fn (TrialBalanceRow $row): array => [
                    'account_code' => $row->account->value,
                    'account_name' => $row->account->label(),
                    'account_type' => $row->type()->value,
                    'group_code' => $row->account->groupCode(),
                    'carries_gold_quantity' => $row->account->carriesGoldQuantity(),
                    'debit_rial' => $row->debitRial,
                    'credit_rial' => $row->creditRial,
                    'debit_fine_mg' => $row->debitFineMg,
                    'credit_fine_mg' => $row->creditFineMg,
                    'balance_rial' => $row->balanceRial(),
                    'balance_side' => $row->side()->value,
                    'net_rial' => $row->netRial(),
                    'net_fine_mg' => $row->netFineMg(),
                ] + $this->display($request, [
                    'debit_rial_display' => Display::rial($row->debitRial),
                    'credit_rial_display' => Display::rial($row->creditRial),
                    'balance_rial_display' => Display::rial($row->balanceRial()),
                    'net_fine_display' => Display::grams($row->netFineMg()),
                ]),
                array_values($balance->rows),
            ),
            'total_debit_rial' => $balance->totalDebitRial,
            'total_credit_rial' => $balance->totalCreditRial,
            'total_debit_fine_mg' => $balance->totalDebitFineMg,
            'total_credit_fine_mg' => $balance->totalCreditFineMg,
            'rial_difference' => $balance->rialDifference(),
            'gold_difference' => $balance->goldDifference(),
            'is_balanced' => $balance->isBalanced(),
        ] + $this->display($request, [
            'total_debit_rial_display' => Display::rial($balance->totalDebitRial),
            'total_credit_rial_display' => Display::rial($balance->totalCreditRial),
            'rial_difference_display' => Display::rial($balance->rialDifference()),
            'gold_difference_display' => Display::grams($balance->goldDifference()),
            'range_from_jalali' => Display::jalali($balance->fromDate, false),
            'range_to_jalali' => Display::jalali($balance->toDate, false),
        ]);
    }
}
