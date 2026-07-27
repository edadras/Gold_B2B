<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain;

/**
 * Delivery priority (docs/03-domain/15-notification-reporting.md §15.4).
 *
 * The two rules that matter: CRITICAL overrides user preferences and quiet
 * hours, and quiet hours apply only to LOW and NORMAL. HIGH sits deliberately
 * in between — it disturbs a sleeping user, but it still respects a channel the
 * user switched off.
 */
enum Priority: string
{
    case LOW = 'LOW';
    case NORMAL = 'NORMAL';
    case HIGH = 'HIGH';
    case CRITICAL = 'CRITICAL';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /** CRITICAL is delivered whatever the user asked for: money and deadlines. */
    public function overridesPreferences(): bool
    {
        return $this === self::CRITICAL;
    }

    /** §15.4 rule 2. */
    public function respectsQuietHours(): bool
    {
        return $this === self::LOW || $this === self::NORMAL;
    }

    public function label(): string
    {
        return match ($this) {
            self::LOW => 'کم',
            self::NORMAL => 'عادی',
            self::HIGH => 'بالا',
            self::CRITICAL => 'بحرانی',
        };
    }
}
