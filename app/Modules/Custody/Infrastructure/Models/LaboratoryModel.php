<?php

declare(strict_types=1);

namespace App\Modules\Custody\Infrastructure\Models;

use App\Modules\Custody\Domain\Enums\AccreditationLevel;
use Illuminate\Database\Eloquent\Model;

/** @property int $id */
final class LaboratoryModel extends Model
{
    protected $table = 'laboratories';

    protected $guarded = [];

    protected $casts = [
        'accreditation_level' => AccreditationLevel::class,
        'trust_score' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function isUsable(): bool
    {
        return $this->status === 'ACTIVE';
    }
}
