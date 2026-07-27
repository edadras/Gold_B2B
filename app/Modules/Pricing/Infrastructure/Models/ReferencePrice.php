<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $instrument_id
 * @property int $fine_gram_rial
 * @property int $ounce_usd_micro
 * @property int $usd_irr
 * @property \Carbon\CarbonImmutable $computed_at
 * @property array<int, int> $source_tick_ids
 * @property string $mode
 */
final class ReferencePrice extends Model
{
    protected $table = 'reference_prices';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'fine_gram_rial' => 'int',
            'ounce_usd_micro' => 'int',
            'usd_irr' => 'int',
            'computed_at' => 'immutable_datetime',
            'source_tick_ids' => 'array',
        ];
    }
}
