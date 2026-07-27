<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Infrastructure\Models;

use App\Modules\Settlement\Domain\CrossSettlementStatus;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One cross-settlement agreement (§5.7).
 *
 * @property int $id
 * @property string $cross_code
 * @property int $rial_settlement_id
 * @property int $gold_settlement_id
 * @property int $debtor_org_id
 * @property int $creditor_org_id
 * @property int $agreed_rate_rial
 * @property int $rial_obligation_rial
 * @property int $gold_obligation_mg
 * @property int $gold_applied_mg
 * @property int $rial_discharged_rial
 * @property int $gold_value_rial
 * @property int $rounding_rial
 * @property int $gold_remaining_mg
 * @property int $rial_remaining_rial
 * @property CrossSettlementStatus $status
 * @property int $proposed_by_org_id
 */
final class CrossSettlementModel extends Model
{
    protected $table = 'cross_settlements';

    protected $guarded = [];

    protected $casts = [
        'status' => CrossSettlementStatus::class,
        'rial_settlement_id' => 'int',
        'gold_settlement_id' => 'int',
        'debtor_org_id' => 'int',
        'creditor_org_id' => 'int',
        'agreed_rate_rial' => 'int',
        'rial_obligation_rial' => 'int',
        'gold_obligation_mg' => 'int',
        'gold_applied_mg' => 'int',
        'rial_discharged_rial' => 'int',
        'gold_value_rial' => 'int',
        'rounding_rial' => 'int',
        'gold_remaining_mg' => 'int',
        'rial_remaining_rial' => 'int',
        'proposed_by_org_id' => 'int',
        'proposed_by_user_id' => 'int',
        'debtor_agreed_by_user_id' => 'int',
        'creditor_agreed_by_user_id' => 'int',
        'debtor_agreed_at' => 'datetime',
        'creditor_agreed_at' => 'datetime',
        'rejected_at' => 'datetime',
        'executed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The rate as agreed, never as quoted now.
     *
     * Every conversion in this module goes through this accessor precisely so
     * that there is no code path where a live price could be substituted.
     */
    public function agreedRate(): PricePerFineGram
    {
        return PricePerFineGram::fromRial($this->agreed_rate_rial);
    }

    public function bothPartiesAgreed(): bool
    {
        return $this->debtor_agreed_at !== null && $this->creditor_agreed_at !== null;
    }

    public function involves(int $organizationId): bool
    {
        return $organizationId === $this->debtor_org_id || $organizationId === $this->creditor_org_id;
    }

    /** @param Builder<self> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [
            CrossSettlementStatus::PROPOSED->value,
            CrossSettlementStatus::AGREED->value,
        ]);
    }

    /** @param Builder<self> $query */
    public function scopeForOrganization(Builder $query, int $organizationId): void
    {
        $query->where(function (Builder $q) use ($organizationId): void {
            $q->where('debtor_org_id', $organizationId)
                ->orWhere('creditor_org_id', $organizationId);
        });
    }
}
