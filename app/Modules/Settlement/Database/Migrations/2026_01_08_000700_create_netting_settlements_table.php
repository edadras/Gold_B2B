<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * netting_settlements — docs/03-domain/05-settlement.md §5.6.
 *
 * Which obligations a batch consumed, and in which direction each one ran.
 * §5.6 shows only (batch_id, settlement_id); the three extra columns record the
 * obligation as the batch saw it, so a batch can be audited long after the
 * settlement row has moved on.
 *
 * Invariant N5 ("no settlement in two batches") cannot be a UNIQUE key on
 * settlement_id, because a cancelled batch must leave its settlements free to
 * be netted again tomorrow and rows here are never deleted. NettingService
 * enforces it by rejecting any settlement already attached to a batch in
 * PROPOSED / ACCEPTING / EXECUTING / EXECUTED.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE netting_settlements (
              batch_id             BIGINT UNSIGNED NOT NULL,
              settlement_id        BIGINT UNSIGNED NOT NULL,
              from_organization_id BIGINT UNSIGNED NOT NULL,
              to_organization_id   BIGINT UNSIGNED NOT NULL,
              amount               BIGINT UNSIGNED NOT NULL,
              created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

              PRIMARY KEY (batch_id, settlement_id),
              KEY idx_settlement (settlement_id),

              CONSTRAINT chk_ns_amount_positive CHECK (amount > 0),
              CONSTRAINT chk_ns_parties_differ
                CHECK (from_organization_id <> to_organization_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('netting_settlements');
    }
};
