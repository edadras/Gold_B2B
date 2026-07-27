<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Domain;

/**
 * Lifecycle of one event → one endpoint.
 *
 *   QUEUED    → created, or waiting for the next rung of the retry ladder.
 *   DELIVERED → the receiver answered 2xx (§3.10 «پاسخ موفق: هر کد ۲xx»).
 *   FAILED    → this attempt failed and another is scheduled (next_retry_at).
 *   EXHAUSTED → all seven attempts of §3.10 were spent. Terminal unless a human
 *               retries it through POST /webhook-deliveries/{id}/retry.
 *
 * FAILED and EXHAUSTED are deliberately distinct: the §3.13 example shows a row
 * with `attempts: 7` and `next_retry_at: null`, which is a different operational
 * fact from "failed once, will be retried in thirty seconds", and support staff
 * read this table to answer exactly that question.
 */
enum DeliveryStatus: string
{
    case QUEUED = 'QUEUED';
    case DELIVERED = 'DELIVERED';
    case FAILED = 'FAILED';
    case EXHAUSTED = 'EXHAUSTED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    public function isTerminal(): bool
    {
        return $this === self::DELIVERED || $this === self::EXHAUSTED;
    }
}
