<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * gold_transfers — the delivery half of a settlement.
 *
 * Records which lots delivered the promised fine weight and, crucially, whether
 * any metal actually moved. Pattern 4 of docs/03-domain/05-settlement.md §5.3
 * (CUSTODY_CHANGE) is the efficient case the whole custody layer exists for:
 * custodian and physical_location are identical before and after, only the
 * owner changes, and physical_movement stays 0.
 *
 * custodian_*_before / _after are stored as plain strings rather than a foreign
 * key into gold_lots because Custody owns that table (AGENT_BRIEF rule 7); this
 * is the settlement's own attestation of what it observed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE gold_transfers (
              id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              settlement_id          BIGINT UNSIGNED NOT NULL,
              from_organization_id   BIGINT UNSIGNED NOT NULL,
              to_organization_id     BIGINT UNSIGNED NOT NULL,

              fine_weight_mg         BIGINT UNSIGNED NOT NULL,
              delivery_method        ENUM('VAULT_TRANSFER','PHYSICAL_HANDOVER',
                                          'CUSTODY_CHANGE','NETTED') NOT NULL,
              lot_ids                JSON NULL,
              lot_count              SMALLINT UNSIGNED NOT NULL DEFAULT 0,
              split_performed        TINYINT(1) NOT NULL DEFAULT 0,

              custodian_type_before  VARCHAR(20) NULL,
              custodian_id_before    BIGINT UNSIGNED NULL,
              location_before        VARCHAR(50) NULL,
              custodian_type_after   VARCHAR(20) NULL,
              custodian_id_after     BIGINT UNSIGNED NULL,
              location_after         VARCHAR(50) NULL,
              physical_movement      TINYINT(1) NOT NULL DEFAULT 0,

              handover_code          VARCHAR(20) NULL,
              transferred_at         TIMESTAMP NULL,
              confirmed_by_user_id   BIGINT UNSIGNED NULL,
              transaction_group      CHAR(36) NULL,

              created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,

              PRIMARY KEY (id),
              KEY idx_settlement (settlement_id),
              KEY idx_from (from_organization_id, transferred_at),
              KEY idx_to (to_organization_id, transferred_at),

              CONSTRAINT chk_gt_weight_positive CHECK (fine_weight_mg > 0),
              CONSTRAINT chk_gt_parties_differ
                CHECK (from_organization_id <> to_organization_id),
              CONSTRAINT chk_gt_custody_change_is_static
                CHECK (delivery_method <> 'CUSTODY_CHANGE' OR physical_movement = 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('gold_transfers');
    }
};
