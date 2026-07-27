<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * vault_waybills — the exit permit issued at step 7 of the withdrawal flow.
 * docs/03-domain/06-custody-vault.md §6.4.
 *
 * The one-time code is only ever stored as a SHA-256 hash; the plaintext is
 * returned once to the caller so it can be sent to the owner's mobile.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE vault_waybills (
              id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              waybill_no               VARCHAR(30) NOT NULL,
              custody_operation_id     BIGINT UNSIGNED NOT NULL,
              vault_id                 BIGINT UNSIGNED NOT NULL,
              owner_organization_id    BIGINT UNSIGNED NOT NULL,
              gold_lot_ids             JSON NOT NULL,

              receiver_name            VARCHAR(191) NULL,
              receiver_national_id_hash CHAR(64) NULL,

              one_time_code_hash       CHAR(64) NOT NULL,
              code_expires_at          TIMESTAMP NOT NULL,
              code_used_at             TIMESTAMP NULL,
              code_attempts            TINYINT UNSIGNED NOT NULL DEFAULT 0,
              qr_token                 CHAR(64) NOT NULL,

              status                   ENUM('ISSUED','USED','EXPIRED','CANCELLED')
                                         NOT NULL DEFAULT 'ISSUED',

              issued_by_user_id        BIGINT UNSIGNED NOT NULL,
              issued_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

              created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,

              PRIMARY KEY (id),
              UNIQUE KEY uq_waybill_no (waybill_no),
              UNIQUE KEY uq_waybill_operation (custody_operation_id),
              UNIQUE KEY uq_waybill_qr (qr_token),
              KEY idx_waybill_vault (vault_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_waybills');
    }
};
