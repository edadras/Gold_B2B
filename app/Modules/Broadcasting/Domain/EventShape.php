<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Domain;

use BackedEnum;

/**
 * A tolerant reader over a domain event this module is not allowed to import.
 *
 * Broadcasting sits at the bottom of the dependency graph with Shared and
 * Identity above it (tests/Architecture/ArchitectureTest.php), so it subscribes
 * to Trading, Ledger, Settlement and Pricing events by *string* class name and
 * never type-hints them. That buys module independence at the cost of static
 * guarantees about the payload, so every read goes through here:
 *
 *   · a missing property reads as null, it does not throw;
 *   · a property of the wrong type reads as null rather than being coerced —
 *     silently turning "abc" into 0 rial is worse than not broadcasting;
 *   · every reader returns null on a miss, so a listener can say
 *     `if (... === null) { return; }` and drop the frame — the ...Or() variants
 *     exist only for the fields where a documented default is genuinely safe.
 *
 * The consequence is the rule the tests pin down: an upstream module changing
 * or renaming a field degrades this module to "broadcasts nothing", never to
 * "throws inside an event listener and takes the dispatching request with it".
 */
final readonly class EventShape
{
    /** @param array<string, mixed> $values */
    private function __construct(private array $values) {}

    public static function of(object $event): self
    {
        return new self(get_object_vars($event));
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    public function has(string $property): bool
    {
        return array_key_exists($property, $this->values);
    }

    /** Reads an int-typed property; null when absent or not an int. */
    public function int(string $property): ?int
    {
        $value = $this->values[$property] ?? null;

        // is_int only: a numeric string on an event that promises an int means
        // the producer changed shape, and guessing is how rounding bugs start.
        return is_int($value) ? $value : null;
    }

    public function intOr(string $property, int $default): int
    {
        return $this->int($property) ?? $default;
    }

    public function string(string $property): ?string
    {
        $value = $this->values[$property] ?? null;

        if (is_string($value)) {
            return $value;
        }

        return $value instanceof BackedEnum && is_string($value->value)
            ? $value->value
            : null;
    }

    public function stringOr(string $property, string $default): string
    {
        return $this->string($property) ?? $default;
    }

    public function bool(string $property): ?bool
    {
        $value = $this->values[$property] ?? null;

        return is_bool($value) ? $value : null;
    }

    /**
     * A list-of-lists such as a depth ladder. Anything that is not an array
     * reads as null; nested values are left alone.
     *
     * @return array<int, mixed>|null
     */
    public function list(string $property): ?array
    {
        $value = $this->values[$property] ?? null;

        return is_array($value) ? array_values($value) : null;
    }

    /**
     * The first of the named properties that reads as an int.
     *
     * Producers rename things — TradeExecuted carries both `grossAmount` and
     * `grossAmountRial` for exactly this reason — so a reader that accepts
     * either spelling survives the rename it was not told about.
     *
     * @param  list<string>  $properties
     */
    public function firstInt(array $properties): ?int
    {
        foreach ($properties as $property) {
            $value = $this->int($property);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /** @param list<string> $properties */
    public function firstString(array $properties): ?string
    {
        foreach ($properties as $property) {
            $value = $this->string($property);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }
}
