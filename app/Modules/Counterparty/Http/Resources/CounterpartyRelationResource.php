<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Http\Resources;

use App\Modules\Counterparty\Contracts\RelationSnapshot;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * One bilateral relation — `GET /counterparties` and `GET /counterparties/{orgId}`
 * (docs §2.11).
 *
 * Sign convention, carried straight through from
 * docs/03-domain/10-counterparty.md §10.2: a positive balance means the
 * counterparty owes us. The `is_gold_receivable` / `is_rial_receivable` booleans
 * exist so a client never has to re-derive that from the sign and get it
 * backwards.
 *
 * `internal_note` is absent because `RelationSnapshot` has no field for it —
 * the read model drops it deliberately, so the owner's private annotation
 * cannot escape through any consumer of the snapshot.
 *
 * @mixin RelationSnapshot
 */
final class CounterpartyRelationResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var RelationSnapshot $relation */
        $relation = $this->resource;

        return $relation->toArray() + [
            'is_gold_receivable' => $relation->isGoldReceivable(),
            'is_rial_receivable' => $relation->isRialReceivable(),
        ] + $this->display($request, [
            'gold_balance_display' => Display::grams($relation->goldBalanceMg),
            'rial_balance_display' => Display::rial($relation->rialBalance),
            'gold_credit_limit_display' => Display::grams($relation->goldCreditLimitMg),
            'rial_credit_limit_display' => Display::rial($relation->rialCreditLimit),
            'total_volume_display' => Display::grams($relation->totalVolumeMg),
            'first_trade_at_jalali' => Display::jalali($relation->firstTradeAt),
            'last_trade_at_jalali' => Display::jalali($relation->lastTradeAt),
        ]);
    }
}
