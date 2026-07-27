<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * otc_offer_history — every round of a negotiation, append-only.
 *
 * "هر مرحله در تاریخچه ثبت می‌شود (برای Audit و رفع اختلاف)" —
 * docs/03-domain/04-trading.md §4.6. A dispute over an OTC trade is settled by
 * reading this table, so nothing in it is ever rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE otc_offer_history (
              id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              otc_offer_id     BIGINT UNSIGNED NOT NULL,
              round_no         SMALLINT UNSIGNED NOT NULL,
              action           ENUM('CREATED','COUNTERED','ACCEPTED','REJECTED',
                                    'CANCELLED','EXPIRED') NOT NULL,

              actor_organization_id BIGINT UNSIGNED NOT NULL,
              actor_user_id    BIGINT UNSIGNED NULL,

              quantity_mg      BIGINT UNSIGNED NULL,
              price_rial       BIGINT UNSIGNED NULL,
              note             VARCHAR(500) NULL,
              metadata         JSON NULL,

              created_at       TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

              PRIMARY KEY (id),
              UNIQUE KEY uq_offer_round (otc_offer_id, round_no, action),
              KEY idx_offer_history (otc_offer_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('otc_offer_history');
    }
};
