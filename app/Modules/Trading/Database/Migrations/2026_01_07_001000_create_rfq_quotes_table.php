<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rfq_quotes — one quoter's answer to an RFQ (§4.7).
 *
 * The soft reservation is deliberately NOT a ledger lock: rule 1 of §4.7 says
 * the quoted balance stays usable in the order book and the platform merely
 * warns when the sum of a member's soft locks exceeds their balance. So
 * `soft_reserved_mg` is a plain number here, and only acceptance converts it
 * into a real ledger reservation recorded in `hard_reservation_entry_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE rfq_quotes (
              id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              quote_code                VARCHAR(20) NOT NULL,
              rfq_id                    BIGINT UNSIGNED NOT NULL,
              quoter_organization_id    BIGINT UNSIGNED NOT NULL,
              quoted_by_user_id         BIGINT UNSIGNED NOT NULL,

              quantity_mg               BIGINT UNSIGNED NOT NULL,
              accepted_mg               BIGINT UNSIGNED NOT NULL DEFAULT 0,
              price_per_gram_rial       BIGINT UNSIGNED NOT NULL,

              soft_reserved_mg          BIGINT UNSIGNED NOT NULL DEFAULT 0,
              soft_reserved_rial        BIGINT UNSIGNED NOT NULL DEFAULT 0,
              hard_reservation_entry_id BIGINT UNSIGNED NULL,

              status                    ENUM('PENDING','ACCEPTED','REJECTED',
                                             'WITHDRAWN','EXPIRED') NOT NULL,
              reject_reason             VARCHAR(500) NULL,
              trade_id                  BIGINT UNSIGNED NULL,

              valid_until               TIMESTAMP NOT NULL,
              responded_at              TIMESTAMP NULL,

              metadata                  JSON NULL,
              created_at                TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
              updated_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                          ON UPDATE CURRENT_TIMESTAMP,

              PRIMARY KEY (id),
              UNIQUE KEY uq_quote_code (quote_code),
              KEY idx_quote_rfq (rfq_id, status, price_per_gram_rial),
              KEY idx_quote_org (quoter_organization_id, status),
              KEY idx_quote_valid (status, valid_until),

              CONSTRAINT chk_quote_accepted_le_quantity CHECK (accepted_mg <= quantity_mg),
              CONSTRAINT chk_quote_quantity_positive CHECK (quantity_mg > 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('rfq_quotes');
    }
};
