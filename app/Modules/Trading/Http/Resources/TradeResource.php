<?php

declare(strict_types=1);

namespace App\Modules\Trading\Http\Resources;

use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use App\Modules\Trading\Infrastructure\Models\Trade;
use Illuminate\Http\Request;

/**
 * An executed trade, as one of its two parties sees it.
 *
 * Only the viewer's own fee and net are shown. The other side's fee is not the
 * viewer's business, and publishing both would let either party derive the
 * platform's fee schedule for the other tier.
 *
 * @mixin Trade
 */
final class TradeResource extends ApiResource
{
    public function __construct(
        mixed $resource,
        private readonly int $viewerOrganizationId,
        private readonly ?string $instrumentCode = null,
    ) {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Trade $trade */
        $trade = $this->resource;

        $isBuyer = (int) $trade->buyer_organization_id === $this->viewerOrganizationId;

        return [
            'id' => (int) $trade->id,
            'trade_code' => (string) $trade->trade_code,
            'instrument' => $this->instrumentCode,
            'trade_source' => $trade->trade_source->value,
            'my_side' => $isBuyer ? 'BUY' : 'SELL',
            'counterparty_organization_id' => $isBuyer
                ? (int) $trade->seller_organization_id
                : (int) $trade->buyer_organization_id,
            'quantity_fine_mg' => (int) $trade->quantity_fine_mg,
            'price_per_gram_rial' => (int) $trade->price_per_gram_rial,
            'gross_amount_rial' => (int) $trade->gross_amount_rial,
            'my_fee_rial' => $isBuyer ? (int) $trade->buyer_fee_rial : (int) $trade->seller_fee_rial,
            'tax_rial' => (int) $trade->tax_rial,
            'my_net_rial' => $isBuyer ? (int) $trade->buyer_net_rial : (int) $trade->seller_net_rial,
            'settlement_type' => $trade->settlement_type->value,
            'delivery_type' => $trade->delivery_type->value,
            'status' => $trade->status->value,
            'executed_at' => Display::iso($trade->executed_at),
            'settlement_deadline' => Display::iso($trade->settlement_deadline),
        ] + $this->display($request, [
            'quantity_display' => Display::grams((int) $trade->quantity_fine_mg),
            'price_display' => Display::rial((int) $trade->price_per_gram_rial),
            'gross_amount_display' => Display::rial((int) $trade->gross_amount_rial),
            'executed_at_jalali' => Display::jalali($trade->executed_at),
        ]);
    }
}
