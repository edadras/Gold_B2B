<?php

declare(strict_types=1);

namespace App\Modules\Custody\Infrastructure\Models;

use App\Modules\Custody\Contracts\DTO\GoldLotSnapshot;
use App\Modules\Custody\Domain\Enums\CustodianType;
use App\Modules\Custody\Domain\Enums\LotShape;
use App\Modules\Custody\Domain\Enums\LotStatus;
use App\Modules\Custody\Domain\Enums\MetalType;
use App\Modules\Custody\Domain\Enums\OriginType;
use App\Modules\Custody\Domain\Enums\PuritySource;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Purity;
use App\Modules\Shared\ValueObjects\Weight;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $lot_code
 * @property int $gross_weight_mg
 * @property int $purity_x10
 * @property int $fine_weight_mg
 * @property int $owner_organization_id
 * @property int $custodian_id
 * @property int $generation
 * @property int $version
 */
final class GoldLotModel extends Model
{
    protected $table = 'gold_lots';

    protected $guarded = [];

    protected $casts = [
        'metal_type' => MetalType::class,
        'purity_source' => PuritySource::class,
        'shape' => LotShape::class,
        'origin_type' => OriginType::class,
        'custodian_type' => CustodianType::class,
        'status' => LotStatus::class,
        'gross_weight_mg' => 'int',
        'purity_x10' => 'int',
        'fine_weight_mg' => 'int',
        'generation' => 'int',
        'version' => 'int',
        'owner_organization_id' => 'int',
        'custodian_id' => 'int',
        'vault_box_id' => 'int',
        'current_assay_id' => 'int',
        'refiner_id' => 'int',
        'refined_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function gross(): Weight
    {
        return Weight::fromMilligrams($this->gross_weight_mg);
    }

    public function fine(): FineWeight
    {
        return FineWeight::fromMilligrams($this->fine_weight_mg);
    }

    public function purity(): Purity
    {
        return Purity::fromScaled($this->purity_x10);
    }

    public function lotStatus(): LotStatus
    {
        return $this->status;
    }

    public function scopeOwnedBy(Builder $query, int $organizationId): Builder
    {
        return $query->where('owner_organization_id', $organizationId);
    }

    public function scopeWithStatus(Builder $query, LotStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    public function scopeAllocatable(Builder $query): Builder
    {
        return $query->where('status', LotStatus::AVAILABLE->value);
    }

    public function toSnapshot(): GoldLotSnapshot
    {
        return new GoldLotSnapshot(
            id: (int) $this->id,
            lotCode: (string) $this->lot_code,
            metalType: $this->metal_type,
            grossWeightMg: (int) $this->gross_weight_mg,
            purityX10: (int) $this->purity_x10,
            fineWeightMg: (int) $this->fine_weight_mg,
            puritySource: $this->purity_source,
            status: $this->status,
            originType: $this->origin_type,
            ownerOrganizationId: (int) $this->owner_organization_id,
            custodianType: $this->custodian_type,
            custodianId: (int) $this->custodian_id,
            vaultBoxId: $this->vault_box_id === null ? null : (int) $this->vault_box_id,
            physicalLocation: $this->physical_location,
            serialNumber: $this->serial_number,
            currentAssayId: $this->current_assay_id === null ? null : (int) $this->current_assay_id,
            generation: (int) $this->generation,
            createdAt: $this->created_at?->toIso8601String() ?? '',
        );
    }
}
