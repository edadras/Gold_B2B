<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * instruments — transcribed verbatim from docs/04-data/02-schema-mysql.md §2.4.
 *
 * The market forms around an instrument, not around "gold" in general
 * (docs/03-domain/04-trading.md §4.1). Every instrument quotes in the unit
 * named by quote_unit; the whole platform quotes GRAM_FINE so purities are
 * directly comparable.
 *
 * Raw DDL rather than a Blueprint so the ENUM definitions match the schema
 * document exactly and the table is created in a single statement — the same
 * precedent the Custody module set.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE instruments (
              id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              code                    VARCHAR(30) NOT NULL,
              name                    VARCHAR(100) NOT NULL,
              metal_type              ENUM('GOLD','SILVER','PLATINUM') NOT NULL DEFAULT 'GOLD',
              min_purity_x10          SMALLINT UNSIGNED NOT NULL,
              quote_unit              ENUM('GRAM_FINE','GRAM_GROSS','MESGHAL') NOT NULL,
              settlement_type         ENUM('INSTANT','T0','T1','T2','T3','ON_ACCOUNT') NOT NULL,
              tick_size_rial          BIGINT UNSIGNED NOT NULL,
              lot_size_mg             BIGINT UNSIGNED NOT NULL,
              min_order_mg            BIGINT UNSIGNED NOT NULL,
              max_order_mg            BIGINT UNSIGNED NOT NULL,
              max_price_deviation_bps INT UNSIGNED NOT NULL DEFAULT 1000,
              status                  ENUM('ACTIVE','PAUSED','CLOSED') NOT NULL,
              created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

              PRIMARY KEY (id),
              UNIQUE KEY uq_code (code),

              CONSTRAINT chk_instrument_purity CHECK (min_purity_x10 <= 10000),
              CONSTRAINT chk_instrument_order_range CHECK (min_order_mg <= max_order_mg),
              CONSTRAINT chk_instrument_lot_positive CHECK (lot_size_mg > 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('instruments');
    }
};
