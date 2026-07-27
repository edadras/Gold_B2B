<?php

declare(strict_types=1);

namespace App\Modules\Trading\Infrastructure\Models;

use App\Modules\Trading\Domain\DeliveryType;
use App\Modules\Trading\Domain\SettlementType;
use App\Modules\Trading\Domain\Side;
use App\Modules\Trading\Domain\TradeSource;
use App\Modules\Trading\Domain\TradeStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable once written (docs/03-domain/04-trading.md §4.5). Nothing in this
 * module ever updates a financial column of a trade; corrections are a
 * reversal trade.
 *
 * @property int $id
 * @property string $trade_code
 * @property int $instrument_id
 * @property TradeSource $trade_source
 * @property ?int $buy_order_id
 * @property ?int $sell_order_id
 * @property int $buyer_organization_id
 * @property int $seller_organization_id
 * @property ?Side $maker_side
 * @property int $quantity_fine_mg
 * @property int $price_per_gram_rial
 * @property int $gross_amount_rial
 * @property int $buyer_fee_rial
 * @property int $seller_fee_rial
 * @property int $tax_rial
 * @property int $buyer_net_rial
 * @property int $seller_net_rial
 * @property SettlementType $settlement_type
 * @property DeliveryType $delivery_type
 * @property TradeStatus $status
 * @property CarbonImmutable $executed_at
 */
final class Trade extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'trades';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'trade_source' => TradeSource::class,
            'maker_side' => Side::class,
            'settlement_type' => SettlementType::class,
            'delivery_type' => DeliveryType::class,
            'status' => TradeStatus::class,
            'quantity_fine_mg' => 'integer',
            'price_per_gram_rial' => 'integer',
            'gross_amount_rial' => 'integer',
            'buyer_fee_rial' => 'integer',
            'seller_fee_rial' => 'integer',
            'tax_rial' => 'integer',
            'buyer_net_rial' => 'integer',
            'seller_net_rial' => 'integer',
            'executed_at' => 'immutable_datetime',
            'settlement_deadline' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
