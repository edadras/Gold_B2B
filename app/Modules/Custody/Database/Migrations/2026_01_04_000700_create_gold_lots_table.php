<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * gold_lots — transcribed from docs/04-data/02-schema-mysql.md §2.3.
 *
 * Every physical piece of metal in the system is one row here. Ownership
 * (owner_organization_id) is deliberately independent of custody
 * (custodian_type/custodian_id): a trade moves the former without touching
 * the latter.
 *
 * Written as raw DDL rather than a Blueprint because the three CHECK
 * constraints are part of the table definition in the schema document, and
 * Laravel's Blueprint cannot express them — issuing them as follow-up ALTERs
 * would rebuild the table three times.
 *
 * No FKs to organizations/users: Identity owns those tables and this module
 * migrates independently of it (AGENT_BRIEF rule 7).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE gold_lots (
              id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              lot_code              VARCHAR(20) NOT NULL,
              metal_type            ENUM('GOLD','SILVER','PLATINUM') NOT NULL DEFAULT 'GOLD',

              gross_weight_mg       BIGINT UNSIGNED NOT NULL,
              purity_x10            SMALLINT UNSIGNED NOT NULL,
              fine_weight_mg        BIGINT UNSIGNED NOT NULL,
              purity_source         ENUM('ASSAYED','DECLARED','ESTIMATED') NOT NULL,

              serial_number         VARCHAR(50) NULL,
              hallmark_code         VARCHAR(50) NULL,
              shape                 ENUM('BAR','GRAIN','SCRAP','COIN','OTHER') NOT NULL,
              qr_token              CHAR(64) NOT NULL,

              origin_type           ENUM('MELT','IMPORT','MEMBER_DEPOSIT','SPLIT',
                                         'MERGE','REASSAY') NOT NULL,
              refiner_id            BIGINT UNSIGNED NULL,
              refined_at            TIMESTAMP NULL,
              current_assay_id      BIGINT UNSIGNED NULL,
              generation            SMALLINT UNSIGNED NOT NULL DEFAULT 1,

              owner_organization_id BIGINT UNSIGNED NOT NULL,

              custodian_type        ENUM('VAULT','ORGANIZATION','LAB','IN_TRANSIT',
                                         'THIRD_PARTY') NOT NULL,
              custodian_id          BIGINT UNSIGNED NOT NULL,
              vault_box_id          BIGINT UNSIGNED NULL,
              physical_location     VARCHAR(50) NULL,

              status                ENUM('UNDER_ASSAY','AVAILABLE','RESERVED',
                                         'IN_SETTLEMENT','IN_TRANSIT','ON_HOLD',
                                         'WITHDRAWN','CONSUMED') NOT NULL,
              hold_reason           VARCHAR(500) NULL,

              version               BIGINT UNSIGNED NOT NULL DEFAULT 0,
              created_by_user_id    BIGINT UNSIGNED NULL,
              created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                      ON UPDATE CURRENT_TIMESTAMP,

              PRIMARY KEY (id),
              UNIQUE KEY uq_lot_code (lot_code),
              UNIQUE KEY uq_qr_token (qr_token),
              KEY idx_owner_status (owner_organization_id, status),
              KEY idx_allocation (owner_organization_id, status, custodian_type, purity_x10),
              KEY idx_custodian (custodian_type, custodian_id),
              KEY idx_box (vault_box_id),
              KEY idx_serial (serial_number),

              CONSTRAINT chk_purity_range CHECK (purity_x10 <= 10000),
              CONSTRAINT chk_fine_le_gross CHECK (fine_weight_mg <= gross_weight_mg),
              CONSTRAINT chk_weights_positive CHECK (gross_weight_mg > 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('gold_lots');
    }
};
