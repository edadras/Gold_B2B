<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Infrastructure\Models;

use App\Modules\Pricing\Domain\PriceType;
use App\Modules\Pricing\Domain\SourceStatus;
use App\Modules\Pricing\Domain\SourceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property SourceType $type
 * @property PriceType $price_type
 * @property int $priority
 * @property string $driver
 * @property string|null $endpoint
 * @property int $max_staleness_s
 * @property int $max_deviation_bps
 * @property int $min_sane_value
 * @property int $max_sane_value
 * @property SourceStatus $status
 * @property bool $is_enabled
 * @property \Carbon\CarbonImmutable|null $last_success_at
 * @property string|null $last_error
 */
final class PriceSource extends Model
{
    use HasFactory;

    protected $table = 'price_sources';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => SourceType::class,
            'price_type' => PriceType::class,
            'status' => SourceStatus::class,
            'priority' => 'int',
            'max_staleness_s' => 'int',
            'max_deviation_bps' => 'int',
            'min_sane_value' => 'int',
            'max_sane_value' => 'int',
            'is_enabled' => 'bool',
            'last_success_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): \App\Modules\Pricing\Database\Factories\PriceSourceFactory
    {
        return \App\Modules\Pricing\Database\Factories\PriceSourceFactory::new();
    }

    /** @param Builder<self> $query */
    public function scopeUsable(Builder $query): void
    {
        $query->where('is_enabled', true)->where('status', '!=', SourceStatus::DOWN->value);
    }

    /** @param Builder<self> $query */
    public function scopeOfType(Builder $query, PriceType $type): void
    {
        $query->where('price_type', $type->value);
    }

    public function isAutomatic(): bool
    {
        return $this->type->isAutomatic();
    }
}
