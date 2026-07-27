<?php

declare(strict_types=1);

namespace App\Modules\Custody\Infrastructure\Models;

use App\Modules\Custody\Contracts\DTO\AssaySnapshot;
use App\Modules\Custody\Domain\Enums\AssayMethod;
use App\Modules\Custody\Domain\Enums\AssayStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $gold_lot_id
 * @property int $purity_x10
 * @property int $fine_weight_mg
 * @property int $gross_weight_mg
 */
final class AssayModel extends Model
{
    protected $table = 'assays';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'method' => AssayMethod::class,
        'status' => AssayStatus::class,
        'gross_weight_mg' => 'int',
        'purity_x10' => 'int',
        'fine_weight_mg' => 'int',
        'gold_lot_id' => 'int',
        'laboratory_id' => 'int',
        'superseded_by_id' => 'int',
        'document_id' => 'int',
        'recorded_by_user_id' => 'int',
        'assayed_at' => 'datetime',
        'valid_until' => 'datetime',
        'verified_by_lab_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function laboratory(): BelongsTo
    {
        return $this->belongsTo(LaboratoryModel::class, 'laboratory_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(GoldLotModel::class, 'gold_lot_id');
    }

    public function toSnapshot(?string $laboratoryName = null): AssaySnapshot
    {
        return new AssaySnapshot(
            id: (int) $this->id,
            assayCode: (string) $this->assay_code,
            goldLotId: (int) $this->gold_lot_id,
            certificateNo: (string) $this->certificate_no,
            laboratoryId: (int) $this->laboratory_id,
            laboratoryName: $laboratoryName ?? $this->getRelationValue('laboratory')?->name,
            method: $this->method,
            grossWeightMg: (int) $this->gross_weight_mg,
            purityX10: (int) $this->purity_x10,
            fineWeightMg: (int) $this->fine_weight_mg,
            assayedAt: $this->assayed_at?->toIso8601String() ?? '',
            validUntil: $this->valid_until?->toIso8601String(),
            status: $this->status,
            supersededById: $this->superseded_by_id === null ? null : (int) $this->superseded_by_id,
            verifiedByLabAt: $this->verified_by_lab_at?->toIso8601String(),
        );
    }
}
