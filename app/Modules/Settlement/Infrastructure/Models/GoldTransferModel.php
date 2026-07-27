<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Infrastructure\Models;

use App\Modules\Settlement\Domain\DeliveryMethod;
use App\Modules\Shared\ValueObjects\FineWeight;
use Illuminate\Database\Eloquent\Model;

/**
 * The delivery leg of a settlement.
 *
 * custodyUnchanged() is the assertion behind §5.3 pattern 4: the buyer owns the
 * metal, and the metal has not moved a centimetre.
 *
 * @property int $id
 * @property int $settlement_id
 * @property int $fine_weight_mg
 * @property DeliveryMethod $delivery_method
 * @property bool $physical_movement
 * @property bool $split_performed
 */
final class GoldTransferModel extends Model
{
    protected $table = 'gold_transfers';

    protected $guarded = [];

    protected $casts = [
        'delivery_method' => DeliveryMethod::class,
        'lot_ids' => 'array',
        'settlement_id' => 'int',
        'from_organization_id' => 'int',
        'to_organization_id' => 'int',
        'fine_weight_mg' => 'int',
        'lot_count' => 'int',
        'custodian_id_before' => 'int',
        'custodian_id_after' => 'int',
        'confirmed_by_user_id' => 'int',
        'split_performed' => 'bool',
        'physical_movement' => 'bool',
        'transferred_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function fine(): FineWeight
    {
        return FineWeight::fromMilligrams($this->fine_weight_mg);
    }

    /** @return list<int> */
    public function lotIds(): array
    {
        /** @var array<int, mixed>|null $ids */
        $ids = $this->lot_ids;

        return array_values(array_map('intval', $ids ?? []));
    }

    public function custodyUnchanged(): bool
    {
        return ! $this->physical_movement
            && $this->custodian_type_before === $this->custodian_type_after
            && (int) $this->custodian_id_before === (int) $this->custodian_id_after
            && $this->location_before === $this->location_after;
    }
}
