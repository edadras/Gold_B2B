<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Middleware;

use App\Modules\Shared\Idempotency\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes state-changing financial endpoints safe to retry.
 *
 * A dropped response on a mobile network must not be able to place a second
 * order. The client sends a UUID; we record it before doing the work and replay
 * the stored response if the same key comes back.
 *
 * docs/02-architecture/04-security.md §4.5
 */
final class HandleIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        $key = $request->header('Idempotency-Key');

        if ($key === null || $key === '') {
            return $this->error('IDEMPOTENCY_KEY_REQUIRED', 'هدر Idempotency-Key الزامی است.', 400);
        }

        if (! Str::isUuid($key)) {
            return $this->error('IDEMPOTENCY_KEY_INVALID', 'مقدار Idempotency-Key باید UUID باشد.', 400);
        }

        $organizationId = (int) ($request->user()->organization_id ?? 0);
        $requestHash = hash('sha256', $request->getContent());

        $existing = IdempotencyKey::query()
            ->where('key', $key)
            ->where('organization_id', $organizationId)
            ->first();

        if ($existing !== null) {
            if ($existing->request_hash !== $requestHash) {
                return $this->error(
                    'IDEMPOTENCY_KEY_REUSED',
                    'این کلید قبلاً با محتوای متفاوتی استفاده شده است.',
                    422,
                );
            }

            if ($existing->status === 'processing') {
                return $this->error(
                    'IDEMPOTENCY_IN_PROGRESS',
                    'درخواست مشابهی در حال پردازش است.',
                    409,
                );
            }

            return response()
                ->json($existing->response_body, $existing->response_code ?? 200)
                ->header('X-Idempotent-Replay', 'true');
        }

        try {
            $record = IdempotencyKey::create([
                'key' => $key,
                'organization_id' => $organizationId,
                'endpoint' => $request->path(),
                'request_hash' => $requestHash,
                'status' => 'processing',
                'locked_at' => now(),
                'expires_at' => now()->addHours((int) config('goldb2b.idempotency.ttl_hours', 24)),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two concurrent requests raced; the loser reports in-progress.
            return $this->error(
                'IDEMPOTENCY_IN_PROGRESS',
                'درخواست مشابهی در حال پردازش است.',
                409,
            );
        }

        $response = $next($request);

        $record->update([
            'status' => $response->isSuccessful() ? 'completed' : 'failed',
            'response_code' => $response->getStatusCode(),
            'response_body' => json_decode($response->getContent() ?: 'null', true),
        ]);

        return $response;
    }

    private function error(string $code, string $message, int $status): Response
    {
        return response()->json([
            'error' => ['code' => $code, 'message' => $message],
        ], $status);
    }
}
