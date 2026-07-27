<?php

declare(strict_types=1);

namespace App\Modules\Custody\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/** @property int $id */
final class VaultAuditModel extends Model
{
    protected $table = 'vault_audits';

    protected $guarded = [];

    protected $casts = [
        'vault_id' => 'int',
        'variances' => 'array',
        'requires_investigation' => 'bool',
        'vault_frozen' => 'bool',
        'expected_lot_count' => 'int',
        'expected_gross_mg' => 'int',
        'expected_fine_mg' => 'int',
        'counted_lot_count' => 'int',
        'counted_gross_mg' => 'int',
        'matched_count' => 'int',
        'within_tolerance_count' => 'int',
        'review_count' => 'int',
        'investigation_count' => 'int',
        'missing_count' => 'int',
        'unknown_count' => 'int',
        'auditor_user_id' => 'int',
        'approved_by_user_id' => 'int',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
