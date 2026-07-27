<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Support;

use Illuminate\Http\Request;

/**
 * Cursor pagination, per docs/05-api/01-conventions.md §1.8.
 *
 * The cursor is base64 of `{"id": 88231}`. Cursors are used rather than offsets
 * on the large lists because a page-2 offset silently skips or repeats rows
 * whenever a new trade lands between the two requests, and on this platform new
 * rows land constantly.
 *
 * A malformed or hostile cursor decodes to null and is treated as "start from
 * the beginning" rather than an error: it is a pagination hint, not input the
 * caller composed by hand.
 */
final class Cursor
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 200;

    public static function limit(Request $request, int $default = self::DEFAULT_LIMIT): int
    {
        $limit = $request->query('limit');

        if (! is_string($limit) && ! is_int($limit)) {
            return $default;
        }

        $value = (int) $limit;

        return $value < 1 ? $default : min($value, self::MAX_LIMIT);
    }

    /** The `id` encoded in the request's cursor, or null. */
    public static function afterId(Request $request): ?int
    {
        $cursor = $request->query('cursor');

        if (! is_string($cursor) || $cursor === '') {
            return null;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($decoded === false) {
            return null;
        }

        /** @var mixed $payload */
        $payload = json_decode($decoded, true);

        if (! is_array($payload) || ! isset($payload['id']) || ! is_numeric($payload['id'])) {
            return null;
        }

        return (int) $payload['id'];
    }

    public static function encode(int $id): string
    {
        return rtrim(strtr(base64_encode(json_encode(['id' => $id], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * Build the `links` block.
     *
     * `next` is null when the page came back shorter than the limit, which is
     * the only reliable end-of-list signal without a second count query.
     *
     * @param  list<int>  $ids  the ids on this page, in the order they were returned
     * @return array{next: string|null, prev: string|null}
     */
    public static function links(Request $request, array $ids, int $limit): array
    {
        $hasMore = count($ids) >= $limit && $ids !== [];

        return [
            'next' => $hasMore ? self::url($request, self::encode((int) end($ids))) : null,
            // Cursor pagination is forward-only here; the client keeps its own
            // history. Advertising a `prev` we cannot honour would be worse
            // than admitting there is none.
            'prev' => null,
        ];
    }

    private static function url(Request $request, string $cursor): string
    {
        $query = $request->query();
        $query['cursor'] = $cursor;

        return $request->path().'?'.http_build_query($query);
    }
}
