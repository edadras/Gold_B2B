<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Infrastructure\Models;

use App\Modules\Dispute\Domain\DisputeStatus;
use App\Modules\Dispute\Domain\DisputeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $case_number
 * @property string $dispute_type
 * @property ?int $trade_id
 * @property int $claimant_org_id
 * @property int $respondent_org_id
 * @property int $claim_gold_mg
 * @property int $claim_rial
 * @property string $status
 * @property ?int $hold_gold_entry_id
 * @property ?int $hold_rial_entry_id
 * @property bool $hold_released
 * @property ?string $decision
 * @property int $awarded_gold_mg
 * @property int $awarded_rial
 */
final class DisputeModel extends Model
{
    protected $table = 'disputes';

    protected $guarded = [];

    protected $casts = [
        'trade_id' => 'int',
        'settlement_id' => 'int',
        'gold_lot_id' => 'int',
        'claimant_org_id' => 'int',
        'respondent_org_id' => 'int',
        'opened_by_user_id' => 'int',
        'claim_gold_mg' => 'int',
        'claim_rial' => 'int',
        'hold_gold_entry_id' => 'int',
        'hold_rial_entry_id' => 'int',
        'hold_released' => 'bool',
        'is_frivolous' => 'bool',
        'mediator_user_id' => 'int',
        'decided_by_user_id' => 'int',
        'awarded_gold_mg' => 'int',
        'awarded_rial' => 'int',
        'reversal_settlement_id' => 'int',
        'reply_deadline_at' => 'datetime',
        'negotiation_deadline_at' => 'datetime',
        'opened_at' => 'datetime',
        'resolved_at' => 'datetime',
        'executed_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function statusEnum(): DisputeStatus
    {
        return DisputeStatus::from($this->status);
    }

    public function typeEnum(): DisputeType
    {
        return DisputeType::from($this->dispute_type);
    }

    /** Whether $organizationId is one of the two parties. */
    public function involves(int $organizationId): bool
    {
        return $organizationId === $this->claimant_org_id
            || $organizationId === $this->respondent_org_id;
    }

    /** @return HasMany<DisputeTimelineModel, $this> */
    public function timeline(): HasMany
    {
        return $this->hasMany(DisputeTimelineModel::class, 'dispute_id')->orderBy('occurred_at');
    }

    /** @return HasMany<DisputeMessageModel, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(DisputeMessageModel::class, 'dispute_id')->orderBy('created_at');
    }

    /** @return HasMany<DisputeEvidenceModel, $this> */
    public function evidences(): HasMany
    {
        return $this->hasMany(DisputeEvidenceModel::class, 'dispute_id');
    }
}
