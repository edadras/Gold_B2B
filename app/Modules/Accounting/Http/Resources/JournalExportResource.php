<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Resources;

use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * `GET /accounting/export` — «خروجی برای نرم‌افزار حسابداری» (§2.13).
 *
 * ONE ROW PER JOURNAL LINE, FULLY DENORMALISED. That is the shape every Iranian
 * accounting package imports: voucher number, date and description repeat on
 * every line so a single sheet stands on its own once it has left the platform.
 *
 * It is served as JSON inside the standard envelope rather than as a text/csv
 * body, deliberately. The envelope of §1.4 is what every client parses, the
 * `_display` companions of §1.12 would be meaningless in a CSV cell, and the
 * rows here are already flat — writing them out as CSV is one loop in whichever
 * tool consumes them, whereas a bare CSV body would make this the only endpoint
 * on the platform with a different contract. Members who want a file download
 * instead have `POST /reports/export`, which produces a real CSV with a signed
 * link.
 *
 * `is_balanced` travels with the rows: an export whose debits do not equal its
 * credits will be rejected by the importing software anyway, and finding that
 * out here is far cheaper than finding it out there.
 */
final class JournalExportResource extends ApiResource
{
    /** @param array{rows: array<int, array<string, mixed>>, totals: array<string, int>} $resource */
    public function __construct(mixed $resource, private readonly string $from = '', private readonly string $to = '')
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array{rows: array<int, array<string, mixed>>, totals: array<string, int>} $export */
        $export = $this->resource;

        $totals = $export['totals'];

        return [
            'range' => ['from' => $this->from, 'to' => $this->to],
            // The header row an importer expects, in the order `rows` uses.
            'columns' => [
                'voucher_no', 'entry_date', 'entry_description', 'source_type', 'source_id',
                'line_no', 'line_set', 'account_code', 'account_name', 'account_type',
                'counterparty_org_id', 'debit_rial', 'credit_rial',
                'debit_fine_mg', 'credit_fine_mg', 'description',
            ],
            'rows' => array_values($export['rows']),
            'totals' => $totals,
            'is_balanced' => $totals['rial_difference'] === 0 && $totals['fine_mg_difference'] === 0,
            'note' => 'فقط اسناد ثبت‌شده (POSTED) صادر می‌شوند؛ مبالغ ریال و وزن‌ها میلی‌گرم خالص هستند.',
        ] + $this->display($request, [
            'totals_display' => [
                'debit_rial' => Display::rial($totals['total_debit_rial']),
                'credit_rial' => Display::rial($totals['total_credit_rial']),
                'debit_fine' => Display::grams($totals['total_debit_fine_mg']),
                'credit_fine' => Display::grams($totals['total_credit_fine_mg']),
            ],
            'range_from_jalali' => $this->from === '' ? null : Display::jalali($this->from, false),
            'range_to_jalali' => $this->to === '' ? null : Display::jalali($this->to, false),
        ]);
    }
}
