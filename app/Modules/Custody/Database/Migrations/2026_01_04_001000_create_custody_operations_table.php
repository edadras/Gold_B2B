<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * custody_operations — docs/03-domain/06-custody-vault.md §6.5.
 *
 * Every physical or logical movement of metal produces exactly one row here.
 * The module-wide invariant is
 *
 *     input_fine_mg = output_fine_mg + loss_fine_mg
 *
 * enforced in the application layer — it spans a nullable column and is
 * asserted by the conservation tests, so a CHECK would only duplicate it
 * partially.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE custody_operations (
              id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              operation_type          VARCHAR(30) NOT NULL,
              vault_id                BIGINT UNSIGNED NULL,
              organization_id         BIGINT UNSIGNED NULL,

              input_lot_ids           JSON NOT NULL,
              output_lot_ids          JSON NULL,
              input_fine_mg           BIGINT UNSIGNED NOT NULL,
              output_fine_mg          BIGINT UNSIGNED NULL,
              loss_fine_mg            BIGINT NOT NULL DEFAULT 0,
              loss_gross_mg           BIGINT NOT NULL DEFAULT 0,

              from_location           VARCHAR(50) NULL,
              to_location             VARCHAR(50) NULL,

              reason                  VARCHAR(500) NULL,
              reference_type          VARCHAR(50) NULL,
              reference_id            BIGINT UNSIGNED NULL,

              requested_by_user_id    BIGINT UNSIGNED NOT NULL,
              approved_by_user_id     BIGINT UNSIGNED NULL,
              executed_by_user_id     BIGINT UNSIGNED NULL,

              status                  ENUM('REQUESTED','APPROVED','EXECUTING',
                                           'COMPLETED','REJECTED','CANCELLED') NOT NULL,
              requires_extra_approval TINYINT(1) NOT NULL DEFAULT 0,

              requested_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              approved_at             TIMESTAMP NULL,
              executed_at             TIMESTAMP NULL,

              photos                  JSON NULL,
              signature_data          JSON NULL,
              notes                   TEXT NULL,

              created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,

              PRIMARY KEY (id),
              KEY idx_op_type_status (operation_type, status),
              KEY idx_op_vault_time (vault_id, created_at),
              KEY idx_op_org_time (organization_id, created_at),
              KEY idx_op_reference (reference_type, reference_id),

              CONSTRAINT chk_op_approver_differs
                CHECK (approved_by_user_id IS NULL OR approved_by_user_id <> requested_by_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('custody_operations');
    }
};
