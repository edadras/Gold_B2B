<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * assays — transcribed from docs/04-data/02-schema-mysql.md §2.3.
 *
 * Certificates are never updated in place: a re-assay writes a new row and
 * flips the previous one to SUPERSEDED with superseded_by_id set.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE assays (
              id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              assay_code          VARCHAR(20) NOT NULL,
              gold_lot_id         BIGINT UNSIGNED NOT NULL,
              certificate_no      VARCHAR(100) NOT NULL,
              laboratory_id       BIGINT UNSIGNED NOT NULL,
              method              ENUM('FIRE_ASSAY','XRF','ICP','OTHER') NOT NULL,

              gross_weight_mg     BIGINT UNSIGNED NOT NULL,
              purity_x10          SMALLINT UNSIGNED NOT NULL,
              fine_weight_mg      BIGINT UNSIGNED NOT NULL,

              assayed_at          TIMESTAMP NOT NULL,
              valid_until         TIMESTAMP NULL,
              document_id         BIGINT UNSIGNED NULL,
              qr_token            CHAR(64) NOT NULL,

              status              ENUM('VALID','SUPERSEDED','DISPUTED','REVOKED') NOT NULL,
              superseded_by_id    BIGINT UNSIGNED NULL,
              verified_by_lab_at  TIMESTAMP NULL,
              recorded_by_user_id BIGINT UNSIGNED NOT NULL,
              created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

              PRIMARY KEY (id),
              UNIQUE KEY uq_assay_code (assay_code),
              UNIQUE KEY uq_qr_token (qr_token),
              UNIQUE KEY uq_lab_certificate (laboratory_id, certificate_no),
              KEY idx_lot (gold_lot_id, status),
              KEY idx_lab (laboratory_id),

              CONSTRAINT chk_assay_purity_range CHECK (purity_x10 <= 10000),
              CONSTRAINT chk_assay_fine_le_gross CHECK (fine_weight_mg <= gross_weight_mg),
              CONSTRAINT chk_assay_gross_positive CHECK (gross_weight_mg > 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('assays');
    }
};
