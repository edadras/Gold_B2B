<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** `/health` and `/health/deep` — docs/05-api/02-endpoints.md §2.17. */
final class HealthController
{
    /**
     * Public liveness. Deliberately says nothing about dependencies: an
     * unauthenticated caller learning that the database is down is a gift to
     * anyone timing an attack.
     */
    public function shallow(): JsonResponse
    {
        return ApiResponse::item([
            'status' => 'ok',
            'service' => 'goldb2b-api',
            'version' => 'v1',
        ]);
    }

    /**
     * Authenticated readiness, with the dependency detail.
     *
     * Returns 503 when anything critical is down, so a load balancer or an
     * uptime probe can act on the status code alone.
     */
    public function deep(): JsonResponse
    {
        $checks = [
            'database' => $this->check(static fn (): bool => DB::connection()->getPdo() !== null),
            'cache' => $this->check(static function (): bool {
                Cache::put('health:probe', '1', 5);

                return Cache::get('health:probe') === '1';
            }),
            'queue' => $this->check(static fn (): bool => config('queue.default') !== null),
        ];

        $healthy = ! in_array(false, array_map(
            static fn (array $c): bool => $c['ok'],
            $checks,
        ), true);

        return ApiResponse::item(
            ['status' => $healthy ? 'ok' : 'degraded', 'checks' => $checks],
            $healthy ? 200 : 503,
        );
    }

    /**
     * @param  callable(): bool  $probe
     * @return array{ok: bool, latency_ms: int, error: string|null}
     */
    private function check(callable $probe): array
    {
        $startedAt = hrtime(true);

        try {
            $ok = $probe();
            $error = null;
        } catch (Throwable $e) {
            $ok = false;
            // The class name, never the message: a connection exception message
            // carries the host and the credentials-shaped DSN.
            $error = $e::class;
        }

        return [
            'ok' => $ok,
            'latency_ms' => intdiv((int) (hrtime(true) - $startedAt), 1_000_000),
            'error' => $error,
        ];
    }
}
