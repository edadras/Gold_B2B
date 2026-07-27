<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Resources;

use App\Modules\Accounting\Domain\SourceType;
use App\Modules\Accounting\Infrastructure\Models\JournalEntryModel;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * One voucher and its lines — `GET /accounting/journal` (§2.13).
 *
 * `reverses_id` / `reversed_by_id` are exposed because §9.7 makes reversal the
 * only correction mechanism: a voucher that has been reversed is still in the
 * journal and the client has to be able to show it struck through rather than
 * pretend it never existed.
 *
 * All amounts are integers — rial and fine milligrams. `Display` is used only
 * for the `_display` companions, which nothing ever parses back; it lives in
 * Shared\Http, outside this module's no-floating-point path, and does its own
 * formatting with integer arithmetic.
 *
 * @mixin JournalEntryModel
 */
final class JournalEntryResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var JournalEntryModel $entry */
        $entry = $this->resource;

        $sourceType = SourceType::tryFrom((string) $entry->source_type);

        return [
            'id' => (int) $entry->id,
            'voucher_no' => (string) $entry->voucher_no,
            'entry_date' => (string) $entry->entry_date,
            'description' => (string) $entry->description,
            'source_type' => (string) $entry->source_type,
            'source_id' => (int) $entry->source_id,
            'status' => (string) $entry->status,
            'total_rial' => (int) $entry->total_rial,
            'total_fine_mg' => (int) $entry->total_fine_mg,
            'reverses_id' => $entry->reverses_id === null ? null : (int) $entry->reverses_id,
            'reversed_by_id' => $entry->reversed_by_id === null ? null : (int) $entry->reversed_by_id,
            'accounting_period_id' => $entry->accounting_period_id === null
                ? null
                : (int) $entry->accounting_period_id,
            'posted_at' => Display::iso($entry->posted_at),
            'created_at' => Display::iso($entry->created_at),
            'lines' => JournalLineResource::collection(
                $entry->relationLoaded('lines') ? $entry->lines : $entry->lines()->orderBy('line_no')->get(),
            )->toArray($request),
        ] + $this->display($request, [
            'total_rial_display' => Display::rial((int) $entry->total_rial),
            'total_fine_display' => Display::grams((int) $entry->total_fine_mg),
            'source_type_display' => $sourceType?->label(),
            'entry_date_jalali' => Display::jalali((string) $entry->entry_date, false),
            'posted_at_jalali' => Display::jalali($entry->posted_at),
        ]);
    }
}
