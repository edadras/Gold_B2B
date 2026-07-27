<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * custody_records — the chain of custody for one lot.
 *
 * Exactly one ACTIVE row per live lot; a movement closes the current row
 * (released_at) and opens a new one. This is what answers "who was holding
 * this piece of metal on date X" during a dispute.
 * docs/03-domain/06-custody-vault.md §6.2 and §6.4 step 9.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE custody_records (
              id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              gold_lot_id            BIGINT UNSIGNED NOT NULL,

              custodian_type         ENUM('VAULT','ORGANIZATION','LAB','IN_TRANSIT',
                                          'THIRD_PARTY') NOT NULL,
              custodian_id           BIGINT UNSIGNED NOT NULL,
              vault_id               BIGINT UNSIGNED NULL,
              vault_box_id           BIGINT UNSIGNED NULL,
              physical_location      VARCHAR(50) NULL,

              gross_weight_mg        BIGINT UNSIGNED NOT NULL,
              fine_weight_mg         BIGINT UNSIGNED NOT NULL,

              received_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              released_at            TIMESTAMP NULL,
              received_operation_id  BIGINT UNSIGNED NULL,
              released_operation_id  BIGINT UNSIGNED NULL,
              received_by_user_id    BIGINT UNSIGNED NULL,
              released_by_user_id    BIGINT UNSIGNED NULL,

              status                 ENUM('ACTIVE','CLOSED') NOT NULL DEFAULT 'ACTIVE',
              notes                  VARCHAR(500) NULL,

              created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,

              PRIMARY KEY (id),
              KEY idx_custody_lot (gold_lot_id, status),
              KEY idx_custody_vault (vault_id, status),
              KEY idx_custody_holder (custodian_type, custodian_id),

              CONSTRAINT chk_custody_fine_le_gross CHECK (fine_weight_mg <= gross_weight_mg)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('custody_records');
    }
};
