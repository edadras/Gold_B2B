<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * trades — transcribed from docs/04-data/02-schema-mysql.md §2.4.
 *
 * A Trade is immutable once written (docs/03-domain/04-trading.md §4.5):
 * corrections are a reversal trade, never an UPDATE of the financial columns.
 *
 * Two deliberate departures from the DDL in the schema document:
 *
 *  1. No `PARTITION BY RANGE (UNIX_TIMESTAMP(executed_at))`. ADR-012 keeps
 *     partitioning for production sizing; locally it buys nothing and MariaDB
 *     would force every unique key to carry the partition column. See
 *     AGENT_BRIEF, "MariaDB note".
 *  2. Consequently the primary key is `(id)` and the trade-code unique is
 *     `(trade_code)` rather than the composite forms the partitioned table
 *     needs. That is strictly tighter, so no row that is legal here would be
 *     rejected by the partitioned definition.
 *
 * The three CHECK constraints are the schema document's two plus a positive
 * quantity guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE trades (
              id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              trade_code              VARCHAR(20) NOT NULL,
              instrument_id           BIGINT UNSIGNED NOT NULL,
              trade_source            ENUM('ORDER_BOOK','OTC','RFQ') NOT NULL,

              buy_order_id            BIGINT UNSIGNED NULL,
              sell_order_id           BIGINT UNSIGNED NULL,
              otc_offer_id            BIGINT UNSIGNED NULL,
              rfq_quote_id            BIGINT UNSIGNED NULL,

              buyer_organization_id   BIGINT UNSIGNED NOT NULL,
              seller_organization_id  BIGINT UNSIGNED NOT NULL,
              maker_side              ENUM('BUY','SELL') NULL,

              quantity_fine_mg        BIGINT UNSIGNED NOT NULL,
              price_per_gram_rial     BIGINT UNSIGNED NOT NULL,
              gross_amount_rial       BIGINT UNSIGNED NOT NULL,

              buyer_fee_rial          BIGINT UNSIGNED NOT NULL DEFAULT 0,
              seller_fee_rial         BIGINT UNSIGNED NOT NULL DEFAULT 0,
              tax_rial                BIGINT UNSIGNED NOT NULL DEFAULT 0,
              buyer_net_rial          BIGINT UNSIGNED NOT NULL,
              seller_net_rial         BIGINT UNSIGNED NOT NULL,

              settlement_type         ENUM('INSTANT','T0','T1','T2','T3','ON_ACCOUNT') NOT NULL,
              delivery_type           ENUM('VAULT_TRANSFER','PHYSICAL_HANDOVER',
                                           'CUSTODY_CHANGE','NETTED') NOT NULL,
              settlement_deadline     TIMESTAMP NOT NULL,
              settlement_id           BIGINT UNSIGNED NULL,

              status                  ENUM('EXECUTED','SETTLING','SETTLED',
                                           'DISPUTED','REVERSED') NOT NULL,

              executed_at             TIMESTAMP(3) NOT NULL,
              created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

              PRIMARY KEY (id),
              UNIQUE KEY uq_trade_code (trade_code),
              KEY idx_buyer (buyer_organization_id, executed_at),
              KEY idx_seller (seller_organization_id, executed_at),
              KEY idx_instrument_time (instrument_id, executed_at),
              KEY idx_source (trade_source, executed_at),

              CONSTRAINT chk_no_self_trade
                CHECK (buyer_organization_id <> seller_organization_id),
              CONSTRAINT chk_amounts_balance
                CHECK (buyer_net_rial = gross_amount_rial + buyer_fee_rial + tax_rial),
              CONSTRAINT chk_trade_quantity_positive
                CHECK (quantity_fine_mg > 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
