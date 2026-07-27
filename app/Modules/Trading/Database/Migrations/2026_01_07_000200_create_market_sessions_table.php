<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * market_sessions — one trading day per instrument (docs/03-domain/04-trading.md §4.8).
 *
 * §2.4 of the schema document does not spell this table out, so the columns are
 * transcribed from the MarketSession block in the domain document and the state
 * machine in docs/11-appendix/02-state-machines.md §2.10.
 *
 * The daily OHLCV columns live here rather than in Pricing's market_quotes
 * because they are session-scoped facts (what the opening auction printed, what
 * the close was) rather than a rolling quote.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE market_sessions (
              id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              instrument_id      BIGINT UNSIGNED NOT NULL,
              session_date       DATE NOT NULL,

              status             ENUM('SCHEDULED','PRE_OPEN','OPEN','PAUSED','CLOSED') NOT NULL,

              pre_open_at        TIMESTAMP NULL,
              opens_at           TIMESTAMP NULL,
              closes_at          TIMESTAMP NULL,

              opened_at          TIMESTAMP NULL,
              paused_at          TIMESTAMP NULL,
              resume_at          TIMESTAMP NULL,
              closed_at          TIMESTAMP NULL,
              pause_reason       VARCHAR(255) NULL,

              opening_price_rial BIGINT UNSIGNED NULL,
              closing_price_rial BIGINT UNSIGNED NULL,
              high_price_rial    BIGINT UNSIGNED NULL,
              low_price_rial     BIGINT UNSIGNED NULL,
              volume_mg          BIGINT UNSIGNED NOT NULL DEFAULT 0,
              trade_count        INT UNSIGNED NOT NULL DEFAULT 0,

              created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,

              PRIMARY KEY (id),
              UNIQUE KEY uq_instrument_date (instrument_id, session_date),
              KEY idx_status (status, session_date),

              CONSTRAINT chk_session_high_low
                CHECK (high_price_rial IS NULL OR low_price_rial IS NULL
                       OR low_price_rial <= high_price_rial)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('market_sessions');
    }
};
