<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $organization_id
 * @property int $quantity_mg
 * @property int $total_cost_rial
 * @property int $average_cost_per_gram
 */
final class InventoryCostBasisModel extends Model
{
    protected $table = 'inventory_cost_basis';

    protected $primaryKey = 'organization_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'int',
        'quantity_mg' => 'int',
        'total_cost_rial' => 'int',
        'average_cost_per_gram' => 'int',
        'lifetime_bought_mg' => 'int',
        'lifetime_sold_mg' => 'int',
        'lifetime_realized_profit' => 'int',
        'last_purchase_at' => 'datetime',
        'last_sale_at' => 'datetime',
    ];
}
