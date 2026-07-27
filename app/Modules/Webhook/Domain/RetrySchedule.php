<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Domain;

/**
 * The retry ladder of docs/05-api/03-realtime-webhooks.md §3.10, as pure logic.
 *
 *   تلاش    تأخیر
 *     ۱     فوری        0s
 *     ۲     ۳۰ ثانیه     30s
 *     ۳     ۲ دقیقه      120s
 *     ۴     ۱۰ دقیقه     600s
 *     ۵     ۱ ساعت       3600s
 *     ۶     ۶ ساعت       21600s
 *     ۷     ۲۴ ساعت      86400s
 *
 * The ladder is a *list of delays before attempts*, not a list of gaps between
 * them, so its length is the attempt cap: seven entries, seven attempts, and
 * `delayAfterFailures(7)` is null — there is no eighth rung. Expressing it that
 * way means the cap and the ladder can never drift apart, which is the bug this
 * class exists to make impossible.
 *
 * No clock, no database, no container: the job asks it "I have failed N times,
 * how long until the next try?" and it answers with an int or null.
 */
final class RetrySchedule
{
    /** @var list<int> */
    public const DEFAULT_DELAYS = [0, 30, 120, 600, 3_600, 21_600, 86_400];

    /** @param list<int> $delays seconds to wait before attempt 1, 2, 3 … */
    public function __construct(private readonly array $delays = self::DEFAULT_DELAYS)
    {
        if ($this->delays === []) {
            throw new \InvalidArgumentException('A retry ladder needs at least one rung.');
        }
    }

    public static function fromConfig(): self
    {
        /** @var list<int> $delays */
        $delays = array_values(array_map(
            'intval',
            (array) config('goldb2b.webhook.retry_delays', self::DEFAULT_DELAYS),
        ));

        return new self($delays === [] ? self::DEFAULT_DELAYS : $delays);
    }

    /** @return list<int> */
    public function delays(): array
    {
        return $this->delays;
    }

    /** Seven, unless an operator has reconfigured the ladder. */
    public function maxAttempts(): int
    {
        return count($this->delays);
    }

    /**
     * Seconds to wait before attempt number `$attempt` (1-based).
     * Null once the ladder is spent.
     */
    public function delayBeforeAttempt(int $attempt): ?int
    {
        return $this->delays[$attempt - 1] ?? null;
    }

    /**
     * Seconds to wait after `$failedAttempts` failures, i.e. the delay before
     * attempt `$failedAttempts + 1`. Null means the delivery is EXHAUSTED.
     */
    public function delayAfterFailures(int $failedAttempts): ?int
    {
        if ($failedAttempts < 0) {
            return null;
        }

        return $this->delayBeforeAttempt($failedAttempts + 1);
    }

    /** True once every rung has been used — §3.10's «پس از ۷ تلاش ناموفق». */
    public function isExhausted(int $failedAttempts): bool
    {
        return $failedAttempts >= $this->maxAttempts();
    }
}
