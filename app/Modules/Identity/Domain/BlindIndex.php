<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use RuntimeException;

/**
 * Deterministic searchable hash over an encrypted column.
 *
 * `organizations.national_id_enc` is encrypted with a randomised cipher, so it
 * cannot be queried or made UNIQUE. `national_id_hash` is the blind index that
 * makes "is this national id already registered?" answerable in one indexed
 * lookup without ever decrypting a row.
 *
 * HMAC-SHA256, not a bare hash: a plain SHA-256 of a 10-digit national id is
 * brute-forceable in seconds. The key lives outside the database
 * (config('app.blind_index_key')) so a database dump alone reveals nothing.
 * Rotating it requires re-hashing every indexed column — see ADR on key
 * management, docs/02-architecture/04-security.md §4.4.
 */
final class BlindIndex
{
    private function __construct() {}

    /**
     * @param  string  $domain  namespaces the index so the same national id in
     *                          two different columns produces two different
     *                          hashes and cannot be correlated across tables
     */
    public static function hash(string $value, string $domain): string
    {
        $key = self::key();

        return hash_hmac('sha256', $domain.':'.$value, $key);
    }

    public static function forNationalId(string $normalizedNationalId): string
    {
        return self::hash($normalizedNationalId, 'national_id');
    }

    public static function forLegalId(string $normalizedLegalId): string
    {
        return self::hash($normalizedLegalId, 'legal_id');
    }

    public static function forIban(string $normalizedIban): string
    {
        return self::hash($normalizedIban, 'iban');
    }

    private static function key(): string
    {
        $key = config('app.blind_index_key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException(
                'BLIND_INDEX_KEY is not configured; encrypted identity columns cannot be indexed.'
            );
        }

        return $key;
    }
}
