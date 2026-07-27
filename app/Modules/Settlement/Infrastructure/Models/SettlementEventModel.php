<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Infrastructure\Models;

use App\Modules\Settlement\Domain\ActorType;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per settlement status change — the audit trail appendix §2.14 rule 1
 * demands. Append-only by convention: nothing in this module updates or deletes
 * a row here.
 *
 * @property int $id
 * @property int $settlement_id
 * @property ?string $from_status
 * @property string $to_status
 * @property ActorType $actor_type
 */
final class SettlementEventModel extends Model
{
    protected $table = 'settlement_events';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'actor_type' => ActorType::class,
        'metadata' => 'array',
        'settlement_id' => 'int',
        'actor_user_id' => 'int',
        'occurred_at' => 'datetime',
    ];
}
