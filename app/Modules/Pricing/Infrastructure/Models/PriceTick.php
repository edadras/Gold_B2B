<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Infrastructure\Models;

use App\Modules\Pricing\Domain\PriceType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rejected ticks are stored too — the audit trail must show what we refused
 * and why (docs/03-domain/07-pricing.md §7.4).
 *
 * @property int $id
 * @property int $source_id
 * @property PriceType $price_type
 * @property int $value
 * @property int $scale
 * @property CarbonImmutable $observed_at
 * @property CarbonImmutable $received_at
 * @property bool $is_accepted
 * @property string|null $rejection_reason
 * @property bool $is_cross_source_outlier
 * @property int|null $effective_value
 */
final class PriceTick extends Model
{
    protected $table = 'price_ticks';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'price_type' => PriceType::class,
            'value' => 'int',
            'scale' => 'int',
            'observed_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'is_accepted' => 'bool',
            'is_cross_source_outlier' => 'bool',
            'effective_value' => 'int',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeAccepted(Builder $query): void
    {
        $query->where('is_accepted', true);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(PriceSource::class, 'source_id');
    }

    /** The median substitute when filter 3 fired, otherwise the raw value. */
    public function usableValue(): int
    {
        return $this->effective_value ?? $this->value;
    }
}
