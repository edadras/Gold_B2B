<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * otc_offers — the bilateral, named-counterparty channel of §4.6.
 *
 * `side` is always expressed from the *initiator's* point of view and never
 * changes, even when the counterparty counters: a counter-offer re-prices the
 * same trade, it does not swap who is buying.
 *
 * `proposer_organization_id` is whose terms are currently on the table, which
 * is the only party who may not accept them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE otc_offers (
              id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              offer_code                VARCHAR(20) NOT NULL,
              instrument_id             BIGINT UNSIGNED NOT NULL,

              initiator_organization_id BIGINT UNSIGNED NOT NULL,
              counterparty_organization_id BIGINT UNSIGNED NOT NULL,
              proposer_organization_id  BIGINT UNSIGNED NOT NULL,
              created_by_user_id        BIGINT UNSIGNED NOT NULL,

              side                      ENUM('BUY','SELL') NOT NULL,
              quantity_mg               BIGINT UNSIGNED NOT NULL,
              price_rial                BIGINT UNSIGNED NOT NULL,
              min_purity_x10            SMALLINT UNSIGNED NULL,

              settlement_type           ENUM('INSTANT','T0','T1','T2','T3','ON_ACCOUNT') NOT NULL,
              delivery_type             ENUM('VAULT_TRANSFER','PHYSICAL_HANDOVER',
                                             'CUSTODY_CHANGE','NETTED') NOT NULL,

              status                    ENUM('PENDING','COUNTERED','ACCEPTED','REJECTED',
                                             'CANCELLED','EXPIRED') NOT NULL,
              round_count               SMALLINT UNSIGNED NOT NULL DEFAULT 0,
              max_rounds                SMALLINT UNSIGNED NOT NULL DEFAULT 5,

              reservation_entry_id      BIGINT UNSIGNED NULL,
              reserved_amount           BIGINT UNSIGNED NOT NULL DEFAULT 0,
              trade_id                  BIGINT UNSIGNED NULL,

              expires_at                TIMESTAMP NOT NULL,
              responded_at              TIMESTAMP NULL,
              closed_at                 TIMESTAMP NULL,

              metadata                  JSON NULL,
              created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                          ON UPDATE CURRENT_TIMESTAMP,

              PRIMARY KEY (id),
              UNIQUE KEY uq_offer_code (offer_code),
              KEY idx_initiator (initiator_organization_id, status),
              KEY idx_counterparty (counterparty_organization_id, status),
              KEY idx_otc_expires (status, expires_at),

              CONSTRAINT chk_otc_not_self
                CHECK (initiator_organization_id <> counterparty_organization_id),
              CONSTRAINT chk_otc_rounds CHECK (round_count <= max_rounds),
              CONSTRAINT chk_otc_quantity_positive CHECK (quantity_mg > 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('otc_offers');
    }
};
