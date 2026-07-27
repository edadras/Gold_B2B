<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Resources;

use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Infrastructure\Models\LedgerEntryModel;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * One append-only ledger row.
 *
 * `row_hash` and `prev_hash` are exposed on purpose: they are the member's
 * means of verifying that the statement it downloaded has not been rewritten,
 * which is the entire point of the hash chain. `account_id` is not exposed —
 * it is an internal surrogate and the bucket is the meaningful fact.
 *
 * @mixin LedgerEntryModel
 */
final class LedgerEntryResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var LedgerEntryModel $entry */
        $entry = $this->resource;

        $isGold = $entry->asset_type === AssetType::GOLD;

        return [
            'id' => (int) $entry->id,
            'asset_type' => $entry->asset_type->value,
            'entry_type' => (string) $entry->entry_type,
            'direction' => (string) $entry->direction,
            'amount' => (int) $entry->amount,
            'balance_after' => (int) $entry->balance_after,
            'reference_type' => (string) $entry->reference_type,
            'reference_id' => (int) $entry->reference_id,
            'transaction_group' => (string) $entry->transaction_group,
            'description' => $entry->description,
            'created_at' => Display::iso($entry->created_at),
            'row_hash' => $entry->row_hash,
            'prev_hash' => $entry->prev_hash,
        ] + $this->display($request, [
            'amount_display' => $isGold
                ? Display::grams((int) $entry->amount)
                : Display::rial((int) $entry->amount),
            'balance_after_display' => $isGold
                ? Display::grams((int) $entry->balance_after)
                : Display::rial((int) $entry->balance_after),
            'created_at_jalali' => Display::jalali($entry->created_at),
        ]);
    }
}
