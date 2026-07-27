<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Infrastructure;

use App\Modules\Counterparty\Domain\ConfirmationStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $counterparty_org_id
 * @property int $requester_gold_mg
 * @property int $requester_rial
 * @property int|null $responder_gold_mg
 * @property int|null $responder_rial
 * @property ConfirmationStatus $status
 */
final class BalanceConfirmation extends Model
{
    protected $table = 'balance_confirmations';

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'integer',
        'counterparty_org_id' => 'integer',
        'requester_gold_mg' => 'integer',
        'requester_rial' => 'integer',
        'responder_gold_mg' => 'integer',
        'responder_rial' => 'integer',
        'requested_by_user_id' => 'integer',
        'responded_by_user_id' => 'integer',
        'status' => ConfirmationStatus::class,
        'period_start' => 'datetime',
        'as_of' => 'datetime',
        'responded_at' => 'datetime',
    ];
}
