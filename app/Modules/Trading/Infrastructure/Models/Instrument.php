<?php

declare(strict_types=1);

namespace App\Modules\Trading\Infrastructure\Models;

use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Shared\ValueObjects\Purity;
use App\Modules\Trading\Domain\InstrumentStatus;
use App\Modules\Trading\Domain\SettlementType;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $min_purity_x10
 * @property string $quote_unit
 * @property SettlementType $settlement_type
 * @property int $tick_size_rial
 * @property int $lot_size_mg
 * @property int $min_order_mg
 * @property int $max_order_mg
 * @property int $max_price_deviation_bps
 * @property InstrumentStatus $status
 */
final class Instrument extends Model
{
    public $timestamps = false;

    protected $table = 'instruments';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'min_purity_x10' => 'integer',
            'tick_size_rial' => 'integer',
            'lot_size_mg' => 'integer',
            'min_order_mg' => 'integer',
            'max_order_mg' => 'integer',
            'max_price_deviation_bps' => 'integer',
            'settlement_type' => SettlementType::class,
            'status' => InstrumentStatus::class,
            'created_at' => 'datetime',
        ];
    }

    public function minPurity(): Purity
    {
        return Purity::fromScaled($this->min_purity_x10);
    }

    public function isTradable(): bool
    {
        return $this->status->isTradable();
    }

    public function acceptsPrice(PricePerFineGram $price): bool
    {
        return $price->isMultipleOf($this->tick_size_rial);
    }
}
