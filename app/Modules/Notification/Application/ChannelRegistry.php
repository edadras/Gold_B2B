<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application;

use App\Modules\Notification\Contracts\NotificationChannel;
use App\Modules\Notification\Domain\Channel;
use App\Modules\Notification\Infrastructure\Channels\LogChannel;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * Resolves the driver for a channel from `goldb2b.notification.drivers`.
 *
 * This is the seam that keeps the dispatch rules independent of who actually
 * sends the message: point the config at an FCM or Kavenegar class and nothing
 * above this line changes. Drivers are memoised per request because a single
 * dispatch to a whole desk sends on the same channel many times over.
 */
final class ChannelRegistry
{
    /** @var array<string, NotificationChannel> */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    public function for(Channel $channel): NotificationChannel
    {
        if (isset($this->resolved[$channel->value])) {
            return $this->resolved[$channel->value];
        }

        $configured = config('goldb2b.notification.drivers.'.$channel->value);

        if (! is_string($configured) || ! class_exists($configured)) {
            throw new RuntimeException(sprintf(
                'No notification driver configured for channel %s.',
                $channel->value,
            ));
        }

        // LogChannel (and any other multi-channel driver) needs to know which
        // channel it is standing in for.
        $driver = $configured === LogChannel::class
            ? new LogChannel($channel)
            : $this->container->make($configured);

        if (! $driver instanceof NotificationChannel) {
            throw new RuntimeException(sprintf(
                'Driver %s does not implement NotificationChannel.',
                $configured,
            ));
        }

        return $this->resolved[$channel->value] = $driver;
    }
}
