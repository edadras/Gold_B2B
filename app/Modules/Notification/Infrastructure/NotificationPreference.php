<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure;

use App\Modules\Notification\Domain\Category;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $user_id
 * @property Category $category
 * @property bool $in_app
 * @property bool $push
 * @property bool $sms
 * @property bool $email
 * @property string|null $quiet_hours_from
 * @property string|null $quiet_hours_to
 */
final class NotificationPreference extends Model
{
    protected $table = 'notification_preferences';

    protected $guarded = [];

    protected $casts = [
        'user_id' => 'integer',
        'category' => Category::class,
        'in_app' => 'boolean',
        'push' => 'boolean',
        'sms' => 'boolean',
        'email' => 'boolean',
    ];
}
