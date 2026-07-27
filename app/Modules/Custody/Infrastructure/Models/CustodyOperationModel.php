<?php

declare(strict_types=1);

namespace App\Modules\Custody\Infrastructure\Models;

use App\Modules\Custody\Domain\Enums\CustodyOperationStatus;
use App\Modules\Custody\Domain\Enums\CustodyOperationType;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $input_fine_mg
 * @property int $output_fine_mg
 * @property int $loss_fine_mg
 */
final class CustodyOperationModel extends Model
{
    protected $table = 'custody_operations';

    protected $guarded = [];

    protected $casts = [
        'operation_type' => CustodyOperationType::class,
        'status' => CustodyOperationStatus::class,
        'input_lot_ids' => 'array',
        'output_lot_ids' => 'array',
        'photos' => 'array',
        'signature_data' => 'array',
        'input_fine_mg' => 'int',
        'output_fine_mg' => 'int',
        'loss_fine_mg' => 'int',
        'loss_gross_mg' => 'int',
        'vault_id' => 'int',
        'organization_id' => 'int',
        'reference_id' => 'int',
        'requested_by_user_id' => 'int',
        'approved_by_user_id' => 'int',
        'executed_by_user_id' => 'int',
        'requires_extra_approval' => 'bool',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'executed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** input_fine_mg = output_fine_mg + loss_fine_mg — docs §6.5. */
    public function conserves(): bool
    {
        return $this->input_fine_mg === ((int) $this->output_fine_mg) + $this->loss_fine_mg;
    }
}
