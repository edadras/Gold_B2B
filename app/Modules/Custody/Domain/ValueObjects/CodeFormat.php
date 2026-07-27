<?php

declare(strict_types=1);

namespace App\Modules\Custody\Domain\ValueObjects;

use Illuminate\Container\Container;
use InvalidArgumentException;

/**
 * Shared formatting for the module's human-readable identifiers.
 *
 * Reads config('goldb2b.custody.codes.*') when a container is available and
 * falls back to the documented defaults otherwise, so the value objects stay
 * usable in pure unit tests (AGENT_BRIEF rule 6 — no magic numbers in code).
 */
final class CodeFormat
{
    public const DEFAULT_PAD_LENGTH = 8;

    private function __construct() {}

    public static function setting(string $key, string|int $default): string|int
    {
        $container = Container::getInstance();

        if (! $container->bound('config')) {
            return $default;
        }

        /** @var string|int $value */
        $value = $container->make('config')->get("goldb2b.custody.codes.{$key}", $default);

        return $value;
    }

    public static function padLength(): int
    {
        return (int) self::setting('pad_length', self::DEFAULT_PAD_LENGTH);
    }

    public static function format(string $prefix, int $sequence): string
    {
        if ($sequence < 1) {
            throw new InvalidArgumentException("Code sequence must be positive, got {$sequence}");
        }

        return $prefix.str_pad((string) $sequence, self::padLength(), '0', STR_PAD_LEFT);
    }
}
