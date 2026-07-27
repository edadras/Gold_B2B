<?php

declare(strict_types=1);

namespace App\Modules\Trading\Infrastructure\Models;

use App\Modules\Trading\Domain\Side;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $order_id
 * @property int $trade_id
 * @property string $role
 * @property Side $side
 * @property int $quantity_mg
 * @property int $price_rial
 * @property int $gross_amount_rial
 * @property int $fee_rial
 */
final class OrderFill extends Model
{
    public const ROLE_MAKER = 'MAKER';

    public const ROLE_TAKER = 'TAKER';

    public $timestamps = false;

    protected $table = 'order_fills';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'side' => Side::class,
            'quantity_mg' => 'integer',
            'price_rial' => 'integer',
            'gross_amount_rial' => 'integer',
            'fee_rial' => 'integer',
            'filled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
