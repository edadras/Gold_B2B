<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain;

/**
 * Delivery channels (§15.1).
 *
 * IN_APP is not stored in `notification_deliveries`: the in-app notification is
 * the `notifications` row itself, so a delivery row for it would duplicate the
 * record it points at.
 */
enum Channel: string
{
    case IN_APP = 'IN_APP';
    case PUSH = 'PUSH';
    case SMS = 'SMS';
    case EMAIL = 'EMAIL';
    case WEBHOOK = 'WEBHOOK';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /** Channels that produce a `notification_deliveries` row. */
    public static function externalValues(): array
    {
        return ['PUSH', 'SMS', 'EMAIL', 'WEBHOOK'];
    }

    public function isExternal(): bool
    {
        return $this !== self::IN_APP;
    }

    /** Costs real money per message, so §15.4 rule 6 rations it. */
    public function isMetered(): bool
    {
        return $this === self::SMS;
    }

    public function label(): string
    {
        return match ($this) {
            self::IN_APP => 'درون‌برنامه‌ای',
            self::PUSH => 'اعلان موبایل',
            self::SMS => 'پیامک',
            self::EMAIL => 'ایمیل',
            self::WEBHOOK => 'وب‌هوک',
        };
    }
}
