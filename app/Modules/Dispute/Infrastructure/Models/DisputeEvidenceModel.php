<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $dispute_id
 * @property int $submitted_by_org_id
 * @property string $evidence_type
 * @property ?string $file_hash
 */
final class DisputeEvidenceModel extends Model
{
    protected $table = 'dispute_evidences';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'dispute_id' => 'int',
        'submitted_by_org_id' => 'int',
        'submitted_by_user_id' => 'int',
        'document_id' => 'int',
        'submitted_at' => 'datetime',
    ];
}
