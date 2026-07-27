<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * vault_audits — physical reconciliation runs.
 * docs/03-domain/06-custody-vault.md §6.6.
 *
 * The per-line detail lives in the variances JSON column; the counters exist
 * so the vault dashboard does not have to parse it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE vault_audits (
              id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              audit_code             VARCHAR(20) NOT NULL,
              vault_id               BIGINT UNSIGNED NOT NULL,
              status                 ENUM('IN_PROGRESS','COMPLETED','ESCALATED')
                                       NOT NULL DEFAULT 'IN_PROGRESS',

              started_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              completed_at           TIMESTAMP NULL,

              expected_lot_count     INT UNSIGNED NOT NULL DEFAULT 0,
              expected_gross_mg      BIGINT UNSIGNED NOT NULL DEFAULT 0,
              expected_fine_mg       BIGINT UNSIGNED NOT NULL DEFAULT 0,
              counted_lot_count      INT UNSIGNED NOT NULL DEFAULT 0,
              counted_gross_mg       BIGINT UNSIGNED NOT NULL DEFAULT 0,

              matched_count          INT UNSIGNED NOT NULL DEFAULT 0,
              within_tolerance_count INT UNSIGNED NOT NULL DEFAULT 0,
              review_count           INT UNSIGNED NOT NULL DEFAULT 0,
              investigation_count    INT UNSIGNED NOT NULL DEFAULT 0,
              missing_count          INT UNSIGNED NOT NULL DEFAULT 0,
              unknown_count          INT UNSIGNED NOT NULL DEFAULT 0,

              variances              JSON NULL,
              requires_investigation TINYINT(1) NOT NULL DEFAULT 0,
              vault_frozen           TINYINT(1) NOT NULL DEFAULT 0,

              auditor_user_id        BIGINT UNSIGNED NOT NULL,
              approved_by_user_id    BIGINT UNSIGNED NULL,
              notes                  TEXT NULL,

              created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,

              PRIMARY KEY (id),
              UNIQUE KEY uq_audit_code (audit_code),
              KEY idx_audit_vault (vault_id, started_at),

              CONSTRAINT chk_audit_counter_differs
                CHECK (approved_by_user_id IS NULL OR approved_by_user_id <> auditor_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_audits');
    }
};
