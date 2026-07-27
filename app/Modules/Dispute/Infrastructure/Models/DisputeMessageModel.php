<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $dispute_id
 * @property int $sender_org_id
 * @property string $message_type
 * @property int $proposed_gold_mg
 * @property int $proposed_rial
 * @property ?string $resolution
 */
final class DisputeMessageModel extends Model
{
    protected $table = 'dispute_messages';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'dispute_id' => 'int',
        'sender_org_id' => 'int',
        'sender_user_id' => 'int',
        'proposed_gold_mg' => 'int',
        'proposed_rial' => 'int',
        'responds_to_message_id' => 'int',
        'created_at' => 'datetime',
    ];
}
