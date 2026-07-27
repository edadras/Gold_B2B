<?php

declare(strict_types=1);

namespace App\Modules\Notification\Contracts;

use App\Modules\Notification\Domain\Channel;

/**
 * A delivery driver.
 *
 * The dispatcher owns every decision — who receives what, on which channels,
 * whether the hour is quiet, whether this is a duplicate — and a driver owns
 * exactly one thing: getting a rendered message to one destination. That split
 * is what makes the drivers swappable through
 * `config('goldb2b.notification.drivers')` without any rule moving with them.
 */
interface NotificationChannel
{
    public function channel(): Channel;

    public function send(OutboundMessage $message): DeliveryOutcome;
}
