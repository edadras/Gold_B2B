<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Runtime-editable business settings.
 *
 * Values operators change without a deploy (fee rates, market hours, limits)
 * live in `system_settings`; infrastructure constants stay in config/goldb2b.php.
 * Reads are cached because the trading path touches them on every order.
 */
final class SettingsRepository
{
    private const CACHE_PREFIX = 'settings:';

    private const CACHE_TTL = 300;

    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember(
            self::CACHE_PREFIX.$key,
            self::CACHE_TTL,
            function () use ($key, $default) {
                $row = DB::table('system_settings')->where('key', $key)->first();

                if ($row === null) {
                    return $default;
                }

                return $this->cast(json_decode($row->value, true), $row->value_type);
            },
        );
    }

    public function int(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    public function bool(string $key, bool $default = false): bool
    {
        return (bool) $this->get($key, $default);
    }

    public function string(string $key, string $default = ''): string
    {
        return (string) $this->get($key, $default);
    }

    public function set(string $key, mixed $value, string $type = 'string', ?int $userId = null): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => $key],
            [
                'value' => json_encode($value, JSON_UNESCAPED_UNICODE),
                'value_type' => $type,
                'updated_by_user_id' => $userId,
                'updated_at' => now(),
            ],
        );

        Cache::forget(self::CACHE_PREFIX.$key);
    }

    private function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'int', 'decimal' => (int) $value,
            'bool' => (bool) $value,
            'string' => (string) $value,
            default => $value,
        };
    }
}
