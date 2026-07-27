<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application;

use App\Modules\Risk\Jobs\PersistDailyCounter;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Rial;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;

/**
 * docs/03-domain/11-risk-credit.md §11.5.
 *
 * Redis answers the pre-trade check; MySQL keeps the history. The key carries
 * the market-timezone date, so the counters roll over on their own and the
 * midnight reset job the doc mentions is not needed at all — the doc says as
 * much in its own note.
 */
final class DailyCounters
{
    private const TTL_SECONDS = 172_800;   // 48 h — two days of overlap is enough

    public function __construct(private readonly RedisFactory $redis) {}

    public function increment(int $organizationId, FineWeight $volume, Rial $value, ?int $userId = null): void
    {
        $keys = [$this->key($organizationId)];

        if ($userId !== null) {
            $keys[] = $this->userKey($userId);
        }

        $this->connection()->pipeline(function ($pipe) use ($keys, $volume, $value): void {
            foreach ($keys as $key) {
                $pipe->hincrby($key, 'volume_mg', $volume->milligrams);
                $pipe->hincrby($key, 'value_rial', $value->amount);
                $pipe->hincrby($key, 'trade_count', 1);
                $pipe->expire($key, self::TTL_SECONDS);
            }
        });

        // Durable copy for reporting. Queued, never inline: this must not slow
        // the trade path down, and it must not run inside a DB transaction.
        PersistDailyCounter::dispatch(
            $organizationId,
            $this->today(),
            $volume->milligrams,
            $value->amount,
        )->onQueue('risk');
    }

    public function dailyVolumeMg(int $organizationId): int
    {
        return $this->field($organizationId, 'volume_mg');
    }

    public function dailyValueRial(int $organizationId): int
    {
        return $this->field($organizationId, 'value_rial');
    }

    public function tradeCount(int $organizationId): int
    {
        return $this->field($organizationId, 'trade_count');
    }

    /** @return array{volume_mg:int, value_rial:int, trade_count:int} */
    public function snapshot(int $organizationId): array
    {
        /** @var array<string, string> $raw */
        $raw = $this->connection()->hgetall($this->key($organizationId)) ?: [];

        return [
            'volume_mg' => (int) ($raw['volume_mg'] ?? 0),
            'value_rial' => (int) ($raw['value_rial'] ?? 0),
            'trade_count' => (int) ($raw['trade_count'] ?? 0),
        ];
    }

    /** Per-operator counter behind check 10 of §11.4. */
    public function userDailyVolumeMg(int $userId): int
    {
        return $this->fieldAt($this->userKey($userId), 'volume_mg');
    }

    /** Only for tests and for an operator correcting a bad counter. */
    public function reset(int $organizationId, ?int $userId = null): void
    {
        $this->connection()->del($this->key($organizationId));

        if ($userId !== null) {
            $this->connection()->del($this->userKey($userId));
        }
    }

    public function key(int $organizationId, ?CarbonImmutable $at = null): string
    {
        return "risk:daily:{$organizationId}:{$this->dateOf($at)}";
    }

    public function userKey(int $userId, ?CarbonImmutable $at = null): string
    {
        return "risk:daily:user:{$userId}:{$this->dateOf($at)}";
    }

    private function dateOf(?CarbonImmutable $at): string
    {
        return ($at ?? CarbonImmutable::now($this->timezone()))
            ->setTimezone($this->timezone())
            ->toDateString();
    }

    private function field(int $organizationId, string $field): int
    {
        return $this->fieldAt($this->key($organizationId), $field);
    }

    private function fieldAt(string $key, string $field): int
    {
        $value = $this->connection()->hget($key, $field);

        return $value === null || $value === false ? 0 : (int) $value;
    }

    private function today(): string
    {
        return CarbonImmutable::now($this->timezone())->toDateString();
    }

    private function timezone(): string
    {
        return (string) config('goldb2b.market.timezone', 'Asia/Tehran');
    }

    private function connection(): Connection
    {
        return $this->redis->connection(
            (string) config('goldb2b.risk.redis_connection', 'default')
        );
    }
}
