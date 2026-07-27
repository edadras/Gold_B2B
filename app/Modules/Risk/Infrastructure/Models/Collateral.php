<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Models;

use App\Modules\Risk\Domain\CollateralStatus;
use App\Modules\Risk\Domain\CollateralType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organization_id
 * @property CollateralType $type
 * @property CollateralStatus $status
 * @property int $nominal_value_rial
 * @property int|null $fine_weight_mg
 * @property int $acceptance_factor_bps
 * @property string|null $reference
 * @property CarbonImmutable|null $valued_at
 * @property CarbonImmutable|null $expires_at
 */
final class Collateral extends Model
{
    protected $table = 'collaterals';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => CollateralType::class,
            'status' => CollateralStatus::class,
            'nominal_value_rial' => 'int',
            'fine_weight_mg' => 'int',
            'acceptance_factor_bps' => 'int',
            'valued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeCounting(Builder $query): void
    {
        $query->where('status', CollateralStatus::ACTIVE->value);
    }
}
