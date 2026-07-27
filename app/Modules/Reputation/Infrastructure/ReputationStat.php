<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Infrastructure;

use App\Modules\Reputation\Domain\ReputationSnapshot;
use App\Modules\Reputation\Domain\VerificationTier;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $organization_id
 * @property int $total_trades
 * @property int $total_volume_mg
 * @property VerificationTier $verification_tier
 */
final class ReputationStat extends Model
{
    public const CREATED_AT = null;

    protected $table = 'reputation_stats';

    protected $primaryKey = 'organization_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'integer',
        'total_trades' => 'integer',
        'total_volume_mg' => 'integer',
        'settlements_total' => 'integer',
        'settlements_on_time' => 'integer',
        'settlements_late' => 'integer',
        'settlements_defaulted' => 'integer',
        'total_settlement_minutes' => 'integer',
        'disputes_involved' => 'integer',
        'disputes_lost' => 'integer',
        'disputes_frivolous' => 'integer',
        'distinct_counterparties' => 'integer',
        'rfq_received' => 'integer',
        'rfq_responded' => 'integer',
        'rfq_total_response_minutes' => 'integer',
        'quotes_accepted' => 'integer',
        'quotes_filled' => 'integer',
        'maker_volume_mg' => 'integer',
        'taker_volume_mg' => 'integer',
        'verification_tier' => VerificationTier::class,
        'kyc_basic_verified' => 'boolean',
        'kyc_full_verified' => 'boolean',
        'bank_account_verified' => 'boolean',
        'tier_achieved_at' => 'datetime',
        'promotion_locked_until' => 'datetime',
        'member_since' => 'datetime',
        'last_active_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function toSnapshot(): ReputationSnapshot
    {
        return new ReputationSnapshot(
            organizationId: $this->organization_id,
            totalTrades: $this->total_trades,
            totalVolumeMg: $this->total_volume_mg,
            settlementsTotal: $this->settlements_total,
            settlementsOnTime: $this->settlements_on_time,
            settlementsLate: $this->settlements_late,
            settlementsDefaulted: $this->settlements_defaulted,
            totalSettlementMinutes: $this->total_settlement_minutes,
            disputesInvolved: $this->disputes_involved,
            disputesLost: $this->disputes_lost,
            distinctCounterparties: $this->distinct_counterparties,
            rfqReceived: $this->rfq_received,
            rfqResponded: $this->rfq_responded,
            rfqTotalResponseMinutes: $this->rfq_total_response_minutes,
            quotesAccepted: $this->quotes_accepted,
            quotesFilled: $this->quotes_filled,
            makerVolumeMg: $this->maker_volume_mg,
            takerVolumeMg: $this->taker_volume_mg,
            tier: $this->verification_tier,
            memberSince: $this->member_since?->toIso8601String() ?? now()->toIso8601String(),
            lastActiveAt: $this->last_active_at?->toIso8601String(),
            kycBasicVerified: $this->kyc_basic_verified,
            kycFullVerified: $this->kyc_full_verified,
            bankAccountVerified: $this->bank_account_verified,
        );
    }
}
