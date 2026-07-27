<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http;

use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Named rate limiters, per docs/02-architecture/04-security.md §4.6.
 *
 * Limits are keyed on the organization rather than the user or IP for trading
 * endpoints: a member with several traders should share one budget, and a
 * per-IP key would be trivially bypassed from a second office.
 */
final class RateLimiters
{
    public static function register(): void
    {
        // Credential stuffing defence: keyed on both identifier and source.
        RateLimiter::for('auth', static fn (Request $request) => Limit::perMinutes(15, 5)
            ->by(($request->input('mobile') ?? '').'|'.$request->ip())
            ->response(static fn () => self::tooMany()));

        RateLimiter::for('orders', static fn (Request $request) => Limit::perMinute(60)
            ->by(self::organizationKey($request))
            ->response(static fn () => self::tooMany()));

        RateLimiter::for('market-data', static fn (Request $request) => Limit::perMinute(300)
            ->by(self::organizationKey($request))
            ->response(static fn () => self::tooMany()));

        RateLimiter::for('reports', static fn (Request $request) => Limit::perHour(20)
            ->by(self::organizationKey($request))
            ->response(static fn () => self::tooMany()));

        RateLimiter::for('uploads', static fn (Request $request) => Limit::perHour(50)
            ->by(self::organizationKey($request))
            ->response(static fn () => self::tooMany()));

        // Catch-all for everything else on the API surface.
        RateLimiter::for('api', static fn (Request $request) => Limit::perMinute(120)
            ->by(self::organizationKey($request))
            ->response(static fn () => self::tooMany()));
    }

    private static function organizationKey(Request $request): string
    {
        $user = $request->user();

        return $user !== null
            ? 'org:'.($user->organization_id ?? $user->getAuthIdentifier())
            : 'ip:'.$request->ip();
    }

    private static function tooMany(): \Illuminate\Http\JsonResponse
    {
        return ApiResponse::error(
            'RATE_LIMIT_EXCEEDED',
            'تعداد درخواست بیش از حد مجاز است. کمی بعد دوباره تلاش کنید.',
            429,
        );
    }
}
