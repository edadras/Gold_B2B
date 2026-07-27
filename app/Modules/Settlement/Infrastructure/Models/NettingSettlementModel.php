<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Infrastructure\Models;

use App\Modules\Settlement\Domain\Obligation;
use Illuminate\Database\Eloquent\Model;

/**
 * The join between a batch and the obligations it consumed, plus a copy of the
 * obligation itself so a batch stays auditable after the settlement moves on.
 *
 * @property int $batch_id
 * @property int $settlement_id
 * @property int $amount
 */
final class NettingSettlementModel extends Model
{
    protected $table = 'netting_settlements';

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null;

    protected $guarded = [];

    protected $casts = [
        'batch_id' => 'int',
        'settlement_id' => 'int',
        'from_organization_id' => 'int',
        'to_organization_id' => 'int',
        'amount' => 'int',
        'created_at' => 'datetime',
    ];

    public function toObligation(): Obligation
    {
        return new Obligation(
            settlementId: $this->settlement_id,
            fromOrganizationId: (int) $this->from_organization_id,
            toOrganizationId: (int) $this->to_organization_id,
            amount: $this->amount,
        );
    }
}
