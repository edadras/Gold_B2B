<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * order_fills — the per-order view of an execution.
 *
 * One trade produces exactly two fills, one for each side, so an order's fill
 * history is readable without scanning trades twice (once as buyer, once as
 * seller) and the taker/maker role of each side is recorded where it happened.
 *
 * Not in §2.4 of the schema document; required by the module brief because a
 * partial fill must leave a durable record.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE order_fills (
              id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              order_id          BIGINT UNSIGNED NOT NULL,
              trade_id          BIGINT UNSIGNED NOT NULL,
              instrument_id     BIGINT UNSIGNED NOT NULL,
              organization_id   BIGINT UNSIGNED NOT NULL,

              role              ENUM('MAKER','TAKER') NOT NULL,
              side              ENUM('BUY','SELL') NOT NULL,

              quantity_mg       BIGINT UNSIGNED NOT NULL,
              price_rial        BIGINT UNSIGNED NOT NULL,
              gross_amount_rial BIGINT UNSIGNED NOT NULL,
              fee_rial          BIGINT UNSIGNED NOT NULL DEFAULT 0,

              filled_at         TIMESTAMP(3) NOT NULL,
              created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

              PRIMARY KEY (id),
              UNIQUE KEY uq_order_trade (order_id, trade_id),
              KEY idx_order_fills (order_id, id),
              KEY idx_trade_fills (trade_id),
              KEY idx_org_fills (organization_id, filled_at),

              CONSTRAINT chk_fill_quantity_positive CHECK (quantity_mg > 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_fills');
    }
};
