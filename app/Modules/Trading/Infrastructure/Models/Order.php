<?php

declare(strict_types=1);

namespace App\Modules\Trading\Infrastructure\Models;

use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Trading\Domain\OrderStatus;
use App\Modules\Trading\Domain\OrderType;
use App\Modules\Trading\Domain\Side;
use App\Modules\Trading\Domain\TimeInForce;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $order_code
 * @property int $instrument_id
 * @property int $organization_id
 * @property int $created_by_user_id
 * @property Side $side
 * @property OrderType $order_type
 * @property TimeInForce $time_in_force
 * @property int $quantity_mg
 * @property int $filled_mg
 * @property ?int $price_rial
 * @property ?int $max_slippage_bps
 * @property ?int $reservation_entry_id
 * @property int $reserved_amount
 * @property int $consumed_amount
 * @property int $released_amount
 * @property OrderStatus $status
 * @property CarbonImmutable $placed_at
 * @property ?CarbonImmutable $expires_at
 */
final class Order extends Model
{
    protected $table = 'orders';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'side' => Side::class,
            'order_type' => OrderType::class,
            'time_in_force' => TimeInForce::class,
            'status' => OrderStatus::class,
            'quantity_mg' => 'integer',
            'filled_mg' => 'integer',
            'price_rial' => 'integer',
            'max_slippage_bps' => 'integer',
            'reservation_entry_id' => 'integer',
            'reserved_amount' => 'integer',
            'consumed_amount' => 'integer',
            'released_amount' => 'integer',
            'placed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    /** @return HasMany<OrderFill, $this> */
    public function fills(): HasMany
    {
        return $this->hasMany(OrderFill::class, 'order_id');
    }

    public function remainingMg(): int
    {
        return $this->quantity_mg - $this->filled_mg;
    }

    public function remaining(): FineWeight
    {
        return FineWeight::fromMilligrams($this->remainingMg());
    }

    public function quantity(): FineWeight
    {
        return FineWeight::fromMilligrams($this->quantity_mg);
    }

    public function price(): ?PricePerFineGram
    {
        return $this->price_rial === null ? null : PricePerFineGram::fromRial($this->price_rial);
    }

    /** How much of the original reservation is still locked in the ledger. */
    public function outstandingReservation(): int
    {
        return $this->reserved_amount - $this->consumed_amount - $this->released_amount;
    }
}
