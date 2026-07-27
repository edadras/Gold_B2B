<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

use App\Modules\Shared\Exceptions\LimitExceededException;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonSerializable;

/**
 * An inclusive Y-m-d date range, capped at one year.
 *
 * docs/03-domain/15-notification-reporting.md §15.8: «حداکثر بازه گزارش: ۱ سال
 * در یک درخواست». The cap is enforced in the constructor rather than at the
 * query, so there is no path to a report object that spans more than a year —
 * a five-year range is not a slow report, it is a query that will take the
 * replica down and time out anyway.
 */
final readonly class DateRange implements JsonSerializable
{
    /** Inclusive day count that still counts as "one year", leap year included. */
    public const MAX_DAYS = 366;

    public function __construct(public string $from, public string $to)
    {
        foreach ([$this->from, $this->to] as $date) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new InvalidArgumentException("Dates must be Y-m-d, got: {$date}");
            }
        }

        if ($this->to < $this->from) {
            throw new InvalidArgumentException("Range ends before it starts: {$this->from}..{$this->to}");
        }

        $days = $this->days();

        if ($days > self::MAX_DAYS) {
            throw new LimitExceededException('report_range_days', $days, self::MAX_DAYS);
        }
    }

    public static function of(string $from, string $to): self
    {
        return new self($from, $to);
    }

    public static function day(string $date): self
    {
        return new self($date, $date);
    }

    public static function month(int $year, int $month): self
    {
        $first = sprintf('%04d-%02d-01', $year, $month);
        $last = (new DateTimeImmutable($first))->modify('last day of this month')->format('Y-m-d');

        return new self($first, $last);
    }

    /** Inclusive day count: a single day is 1, not 0. */
    public function days(): int
    {
        $from = new DateTimeImmutable($this->from);
        $to = new DateTimeImmutable($this->to);

        return (int) $from->diff($to)->days + 1;
    }

    /** The day before the range starts — where an opening balance is read. */
    public function dayBefore(): string
    {
        return (new DateTimeImmutable($this->from))->modify('-1 day')->format('Y-m-d');
    }

    public function contains(string $date): bool
    {
        return $date >= $this->from && $date <= $this->to;
    }

    /**
     * Rough size signal for the inline-versus-queued decision of §15.8.
     * A wide range is the one thing knowable about cost before running anything.
     */
    public function isNarrow(): bool
    {
        return $this->days() <= 31;
    }

    /** @return array<string, string|int> */
    public function jsonSerialize(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'days' => $this->days()];
    }
}
