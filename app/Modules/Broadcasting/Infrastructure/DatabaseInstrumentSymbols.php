<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Infrastructure;

use App\Modules\Broadcasting\Contracts\InstrumentSymbols;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Resolves instrument id -> code for the public channel name.
 *
 * READS THE TABLE, NOT THE MODEL. AGENT_BRIEF rule 7 forbids importing another
 * module's Eloquent model, and the dependency graph forbids importing Trading
 * at all, so this is a query-builder read of a single immutable reference
 * column (`instruments.code`, UNIQUE, never rewritten because a rename would
 * break every subscribed client). No writes, no joins, no business logic — if
 * Broadcasting ever needs more than a code it should be asking for a port, not
 * widening this.
 *
 * Config wins over the table: `goldb2b.broadcasting.instrument_symbols` lets a
 * deployment slice without Trading's schema (or a test) supply the map
 * directly, and short-circuits the query entirely.
 *
 * Everything is wrapped: an unmigrated database during a deploy must degrade
 * to "no market broadcast", not to an exception inside an event listener that
 * takes the trade-writing request down with it.
 */
final class DatabaseInstrumentSymbols implements InstrumentSymbols
{
    /** @var array<int, string|null> per-request memo, in front of the cache */
    private array $memo = [];

    /** @param array<int, string> $static */
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly Cache $cache,
        private readonly array $static = [],
        private readonly int $ttlSeconds = 300,
    ) {}

    public function codeFor(int $instrumentId): ?string
    {
        if (isset($this->static[$instrumentId])) {
            return $this->static[$instrumentId];
        }

        if (array_key_exists($instrumentId, $this->memo)) {
            return $this->memo[$instrumentId];
        }

        try {
            $code = $this->cache->remember(
                'goldb2b:bcast:instrument:'.$instrumentId,
                $this->ttlSeconds,
                function () use ($instrumentId): ?string {
                    $value = $this->connection->table('instruments')
                        ->where('id', $instrumentId)
                        ->value('code');

                    return is_string($value) ? $value : null;
                },
            );
        } catch (Throwable) {
            $code = null;
        }

        return $this->memo[$instrumentId] = is_string($code) ? $code : null;
    }
}
