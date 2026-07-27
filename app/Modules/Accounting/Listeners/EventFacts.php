<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Listeners;

use DateTimeInterface;
use JsonSerializable;
use Throwable;

/**
 * Defensive readers for events fired by modules this one must not import.
 *
 * Accounting reacts to Trading and Settlement, but their classes are off
 * limits (AGENT_BRIEF rule 7 and the module dependency graph), so listeners are
 * registered against the event's *string* name and receive whatever object the
 * other module chose to fire. The shape is therefore not guaranteed: a property
 * may be named `tradeId` or `id`, may be an int or a numeric string, may not
 * exist at all.
 *
 * These helpers read what is there and answer null when it is not, so a listener
 * can no-op on an unexpected payload instead of throwing inside a queue worker.
 */
final class EventFacts
{
    private function __construct() {}

    /** First readable property among $names, coerced to a positive int. */
    public static function int(object $event, string ...$names): ?int
    {
        foreach ($names as $name) {
            $value = self::raw($event, $name);

            if (is_int($value)) {
                return $value;
            }

            if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
                return (int) $value;
            }

            // Value objects such as FineWeight expose their scalar through
            // jsonSerialize(); reading that is safe without importing the class.
            if (is_object($value) && $value instanceof JsonSerializable) {
                $serialized = $value->jsonSerialize();

                if (is_int($serialized)) {
                    return $serialized;
                }
            }
        }

        return null;
    }

    /** Same as int(), but zero when absent — for optional amounts like fees. */
    public static function intOrZero(object $event, string ...$names): int
    {
        return self::int($event, ...$names) ?? 0;
    }

    public static function string(object $event, string ...$names): ?string
    {
        foreach ($names as $name) {
            $value = self::raw($event, $name);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** A Y-m-d date from whichever timestamp-ish property the event carries. */
    public static function date(object $event, string ...$names): ?string
    {
        foreach ($names as $name) {
            $value = self::raw($event, $name);

            if ($value instanceof DateTimeInterface) {
                return $value->format('Y-m-d');
            }

            if (is_string($value) && preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    private static function raw(object $event, string $name): mixed
    {
        if (! property_exists($event, $name)) {
            return null;
        }

        try {
            /** @phpstan-ignore-next-line dynamic property read is the point of this class */
            return $event->{$name};
        } catch (Throwable) {
            // Uninitialised typed property, or a magic getter that threw.
            return null;
        }
    }
}
