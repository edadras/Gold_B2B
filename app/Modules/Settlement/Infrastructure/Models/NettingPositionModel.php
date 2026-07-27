<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Infrastructure\Models;

use App\Modules\Settlement\Domain\NetPosition;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $batch_id
 * @property int $organization_id
 * @property int $gross_in
 * @property int $gross_out
 * @property int $net_position
 */
final class NettingPositionModel extends Model
{
    protected $table = 'netting_positions';

    protected $guarded = [];

    protected $casts = [
        'batch_id' => 'int',
        'organization_id' => 'int',
        'gross_in' => 'int',
        'gross_out' => 'int',
        'net_position' => 'int',
        'obligation_count' => 'int',
        'accepted_by_user_id' => 'int',
        'rejected_by_user_id' => 'int',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function hasAnswered(): bool
    {
        return $this->accepted_at !== null || $this->rejected_at !== null;
    }

    public function toNetPosition(): NetPosition
    {
        return new NetPosition(
            organizationId: $this->organization_id,
            grossIn: $this->gross_in,
            grossOut: $this->gross_out,
            net: $this->net_position,
            obligationCount: (int) $this->obligation_count,
        );
    }
}
