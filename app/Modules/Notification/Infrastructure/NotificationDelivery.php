<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure;

use App\Modules\Notification\Domain\Channel;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $notification_id
 * @property Channel $channel
 * @property string $status
 * @property string $destination
 */
final class NotificationDelivery extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'notification_deliveries';

    protected $guarded = [];

    protected $casts = [
        'notification_id' => 'integer',
        'attempts' => 'integer',
        'channel' => Channel::class,
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
