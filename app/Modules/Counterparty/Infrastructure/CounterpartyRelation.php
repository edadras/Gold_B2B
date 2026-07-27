<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Infrastructure;

use App\Modules\Counterparty\Contracts\RelationSnapshot;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $counterparty_org_id
 * @property int $gold_balance_mg
 * @property int $rial_balance
 * @property int $gold_credit_limit_mg
 * @property int $rial_credit_limit
 * @property int $total_trade_count
 * @property int $total_volume_mg
 * @property int $overdue_count
 * @property int $dispute_count
 * @property bool $is_trusted
 * @property bool $is_blocked
 * @property bool $auto_accept_otc
 * @property string|null $internal_note
 */
final class CounterpartyRelation extends Model
{
    /**
     * The table carries `updated_at` only — a relation has no meaningful
     * creation event separate from its first movement, which `first_trade_at`
     * already records.
     */
    public const CREATED_AT = null;

    protected $table = 'counterparty_relations';

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'integer',
        'counterparty_org_id' => 'integer',
        'gold_balance_mg' => 'integer',
        'rial_balance' => 'integer',
        'gold_credit_limit_mg' => 'integer',
        'rial_credit_limit' => 'integer',
        'total_trade_count' => 'integer',
        'total_volume_mg' => 'integer',
        'overdue_count' => 'integer',
        'dispute_count' => 'integer',
        'is_trusted' => 'boolean',
        'is_blocked' => 'boolean',
        'auto_accept_otc' => 'boolean',
        'first_trade_at' => 'datetime',
        'last_trade_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function toSnapshot(): RelationSnapshot
    {
        return new RelationSnapshot(
            organizationId: $this->organization_id,
            counterpartyOrgId: $this->counterparty_org_id,
            goldBalanceMg: $this->gold_balance_mg,
            rialBalance: $this->rial_balance,
            goldCreditLimitMg: $this->gold_credit_limit_mg,
            rialCreditLimit: $this->rial_credit_limit,
            totalTradeCount: $this->total_trade_count,
            totalVolumeMg: $this->total_volume_mg,
            overdueCount: $this->overdue_count,
            disputeCount: $this->dispute_count,
            isTrusted: $this->is_trusted,
            isBlocked: $this->is_blocked,
            autoAcceptOtc: $this->auto_accept_otc,
            firstTradeAt: $this->first_trade_at?->toIso8601String(),
            lastTradeAt: $this->last_trade_at?->toIso8601String(),
        );
    }
}
