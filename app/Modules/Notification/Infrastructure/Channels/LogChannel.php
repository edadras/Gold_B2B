<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Channels;

use App\Modules\Notification\Contracts\DeliveryOutcome;
use App\Modules\Notification\Contracts\NotificationChannel;
use App\Modules\Notification\Contracts\OutboundMessage;
use App\Modules\Notification\Domain\Channel;
use Illuminate\Support\Facades\Log;

/**
 * Local stand-in for push, SMS, e-mail and webhook.
 *
 * NO REAL PROVIDER IS INTEGRATED HERE, deliberately. Sending a live SMS from a
 * development machine or a test run is a mistake that costs money and reaches
 * real members, so the local driver writes a log line and reports success.
 *
 * Swapping in the real thing is a config change and one new class per channel:
 *
 *     // config: goldb2b.notification.drivers
 *     'SMS' => App\Modules\Notification\Infrastructure\Channels\KavenegarChannel::class,
 *
 * The new class implements NotificationChannel, and nothing in the dispatcher,
 * the catalogue or the preference rules moves — the six dispatch rules of §15.4
 * live above this seam on purpose.
 *
 * The destination logged is already hashed by the dispatcher; a real driver
 * receives the same message and resolves the true address itself, so raw
 * mobile numbers never reach the log or the deliveries table.
 */
final class LogChannel implements NotificationChannel
{
    public function __construct(private readonly Channel $channel) {}

    public function channel(): Channel
    {
        return $this->channel;
    }

    public function send(OutboundMessage $message): DeliveryOutcome
    {
        $reference = sprintf('log:%s:%s', strtolower($this->channel->value), uniqid('', true));

        Log::channel(config('goldb2b.notification.log_channel', 'stack'))->info(
            'notification.dispatch',
            [
                'channel' => $this->channel->value,
                'code' => $message->code,
                'priority' => $message->priority,
                'organization_id' => $message->organizationId,
                'user_id' => $message->userId,
                'destination' => $message->destination,
                'title' => $message->title,
                'body' => $message->body,
                'notification_id' => $message->notificationId,
                'provider_ref' => $reference,
            ],
        );

        return DeliveryOutcome::sent($reference);
    }
}
