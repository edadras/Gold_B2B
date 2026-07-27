<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Resources;

use App\Modules\Accounting\Domain\AccountCode;
use App\Modules\Accounting\Infrastructure\Models\JournalLineModel;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * One line of a voucher.
 *
 * `line_set` is exposed because §9.3's design turns on it: a voucher holds a
 * RIAL set and a GOLD set that each balance on their own, so a client that
 * summed the two columns together would be adding rial to milligrams. A RIAL
 * line carries no weight and a GOLD line no money — the database CHECK
 * constraints enforce that, and the client can rely on it.
 *
 * @mixin JournalLineModel
 */
final class JournalLineResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var JournalLineModel $line */
        $line = $this->resource;

        $account = AccountCode::tryFromCode((string) $line->account_code);

        return [
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
        ] + $this->display($request, [
            'debit_rial_display' => Display::rial((int) $line->debit_rial),
            'credit_rial_display' => Display::rial((int) $line->credit_rial),
            'debit_fine_display' => Display::grams((int) $line->debit_fine_mg),
            'credit_fine_display' => Display::grams((int) $line->credit_fine_mg),
        ]);
    }
}
