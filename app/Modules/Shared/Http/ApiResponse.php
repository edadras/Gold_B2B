<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http;

use Illuminate\Http\JsonResponse;
use JsonSerializable;

/**
 * The single place API envelopes are built.
 *
 * Shape is fixed by docs/05-api/01-conventions.md §1.4–1.5; clients (including
 * the Flutter app) parse it structurally, so drift here is a breaking change.
 */
final class ApiResponse
{
    /** @param array<string, mixed>|JsonSerializable $data */
    public static function item(mixed $data, int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => self::meta($meta),
        ], $status);
    }

    /** @param iterable<mixed> $items */
    public static function collection(iterable $items, array $links = [], array $meta = []): JsonResponse
    {
        $items = is_array($items) ? $items : iterator_to_array($items);

        return response()->json([
            'data' => array_values($items),
            'meta' => self::meta($meta + ['count' => count($items)]),
            'links' => $links + ['next' => null, 'prev' => null],
        ]);
    }

    public static function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /** @param array<string, mixed> $details */
    public static function error(
        string $code,
        string $message,
        int $status = 422,
        array $details = [],
        ?array $fieldErrors = null,
    ): JsonResponse {
        return response()->json([
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                'details' => $details === [] ? null : $details,
                'field_errors' => $fieldErrors,
            ], static fn ($v) => $v !== null),
            'meta' => self::meta(),
        ], $status);
    }

    /** @return array<string, mixed> */
    private static function meta(array $extra = []): array
    {
        $request = request();

        return array_filter([
            'request_id' => $request?->attributes->get('request_id'),
            'server_time' => now()->toIso8601String(),
        ] + $extra, static fn ($v) => $v !== null);
    }
}
