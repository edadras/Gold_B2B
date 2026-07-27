<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application;

use App\Modules\Notification\Domain\Category;
use App\Modules\Notification\Domain\Channel;
use Illuminate\Support\Carbon;

/**
 * One user's resolved preferences for one category, defaults included.
 *
 * A value object rather than a row, because a user who never opened the
 * settings screen has no row and still has preferences — the defaults of §15.3
 * (in-app and push on, SMS and e-mail off).
 */
final readonly class ChannelPreferences
{
    public function __construct(
        public int $userId,
        public Category $category,
        public bool $inApp = true,
        public bool $push = true,
        public bool $sms = false,
        public bool $email = false,
        public ?string $quietHoursFrom = null,
        public ?string $quietHoursTo = null,
    ) {}

    public function allows(Channel $channel): bool
    {
        return match ($channel) {
            Channel::IN_APP => $this->inApp,
            Channel::PUSH => $this->push,
            Channel::SMS => $this->sms,
            Channel::EMAIL => $this->email,
            // A webhook is an integration the organisation configured, not a
            // per-user channel, so a personal preference does not gate it.
            Channel::WEBHOOK => true,
        };
    }

    public function hasQuietHours(): bool
    {
        return $this->quietHoursFrom !== null && $this->quietHoursTo !== null;
    }

    /**
     * Quiet hours are wall-clock times in the market timezone, and the window
     * usually crosses midnight (22:00 → 07:00), which is why this is not a
     * simple between().
     */
    public function isQuietAt(Carbon $moment): bool
    {
        if (! $this->hasQuietHours()) {
            return false;
        }

        $local = $moment->copy()->setTimezone(config('goldb2b.market.timezone', 'Asia/Tehran'));
        $now = $local->format('H:i:s');

        $from = $this->normalise($this->quietHoursFrom);
        $to = $this->normalise($this->quietHoursTo);

        if ($from === $to) {
            return false;
        }

        if ($from < $to) {
            return $now >= $from && $now < $to;
        }

        // Crosses midnight: quiet from 22:00 to 23:59:59 and 00:00 to 07:00.
        return $now >= $from || $now < $to;
    }

    private function normalise(?string $time): string
    {
        $time = (string) $time;

        return match (substr_count($time, ':')) {
            0 => $time.':00:00',
            1 => $time.':00',
            default => $time,
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'category' => $this->category->value,
            'in_app' => $this->inApp,
            'push' => $this->push,
            'sms' => $this->sms,
            'email' => $this->email,
            'quiet_hours_from' => $this->quietHoursFrom,
            'quiet_hours_to' => $this->quietHoursTo,
        ];
    }
}
