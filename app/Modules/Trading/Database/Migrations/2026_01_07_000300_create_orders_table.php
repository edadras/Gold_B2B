<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * orders — transcribed from docs/04-data/02-schema-mysql.md §2.4.
 *
 * Raw DDL because the two CHECK constraints named in the schema document
 * (`filled_mg <= quantity_mg` and "a LIMIT order must carry a price") are part
 * of the table definition and Blueprint cannot express them; issuing them as
 * follow-up ALTERs would rebuild the table twice.
 *
 * DEVIATION — three columns not in §2.4 were added, all reservation
 * bookkeeping:
 *
 *   reserved_amount   what was locked when the order was placed (rial for BUY,
 *                     fine mg for SELL)
 *   consumed_amount   the part of that reservation that executed fills have
 *                     claimed and which must stay RESERVED until settlement
 *   released_amount   the surplus already handed back, e.g. worked example 1's
 *                     g3 where a buyer reserved at its own price but executed
 *                     at the maker's better one
 *
 * Without them, cancelling the unfilled remainder of a partially filled order
 * would have to re-derive the outstanding lock from fee arithmetic, and the
 * ceil() in F8 makes that non-additive across partial fills. Keeping the three
 * running totals on the row makes the release exact by construction.
 *
 * No FKs to organizations/users/instruments: Identity owns the first two and
 * this module must migrate independently of it (AGENT_BRIEF rule 7); the
 * schema document carries none either.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE orders (
              id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              order_code            VARCHAR(20) NOT NULL,
              instrument_id         BIGINT UNSIGNED NOT NULL,
              organization_id       BIGINT UNSIGNED NOT NULL,
              created_by_user_id    BIGINT UNSIGNED NOT NULL,
              representative_id     BIGINT UNSIGNED NULL,

              side                  ENUM('BUY','SELL') NOT NULL,
              order_type            ENUM('MARKET','LIMIT') NOT NULL,
              time_in_force         ENUM('DAY','GTC','GTD','IOC','FOK') NOT NULL DEFAULT 'DAY',

              quantity_mg           BIGINT UNSIGNED NOT NULL,
              filled_mg             BIGINT UNSIGNED NOT NULL DEFAULT 0,
              price_rial            BIGINT UNSIGNED NULL,
              max_slippage_bps      INT UNSIGNED NULL,

              reservation_entry_id  BIGINT UNSIGNED NULL,
              reserved_amount       BIGINT UNSIGNED NOT NULL DEFAULT 0,
              consumed_amount       BIGINT UNSIGNED NOT NULL DEFAULT 0,
              released_amount       BIGINT UNSIGNED NOT NULL DEFAULT 0,

              status                ENUM('PENDING','OPEN','PARTIALLY_FILLED','FILLED',
                                         'CANCELLED','REJECTED','EXPIRED') NOT NULL,
              reject_reason         VARCHAR(500) NULL,

              placed_at             TIMESTAMP(3) NOT NULL,
              expires_at            TIMESTAMP NULL,
              closed_at             TIMESTAMP NULL,

              metadata              JSON NULL,
              created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                      ON UPDATE CURRENT_TIMESTAMP,

              PRIMARY KEY (id),
              UNIQUE KEY uq_order_code (order_code),
              KEY idx_orderbook (instrument_id, side, status, price_rial, placed_at),
              KEY idx_org_status (organization_id, status),
              KEY idx_expires (status, expires_at),

              CONSTRAINT chk_filled_le_quantity CHECK (filled_mg <= quantity_mg),
              CONSTRAINT chk_limit_has_price
                CHECK (order_type <> 'LIMIT' OR price_rial IS NOT NULL),
              CONSTRAINT chk_quantity_positive CHECK (quantity_mg > 0),
              CONSTRAINT chk_reservation_accounting
                CHECK (consumed_amount + released_amount <= reserved_amount)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
