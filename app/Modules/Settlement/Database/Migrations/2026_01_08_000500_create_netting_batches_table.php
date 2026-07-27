<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * netting_batches — docs/03-domain/05-settlement.md §5.6, "مدل داده تهاتر".
 *
 * Status follows the NettingBatch machine of appendix §2.9. A batch only
 * reaches EXECUTED when every participant has accepted (invariant N6 and
 * ADR-009: netting is never automatic).
 *
 * N3 (net_transfer_count <= gross_transfer_count) is a CHECK rather than a
 * runtime assertion because it is a property of the row, not of a code path.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE netting_batches (
              id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              batch_code           VARCHAR(20) NOT NULL,
              batch_date           DATE NOT NULL,
              netting_type         ENUM('BILATERAL','MULTILATERAL') NOT NULL,
              asset_type           ENUM('GOLD','RIAL') NOT NULL,
              status               ENUM('PROPOSED','ACCEPTING','EXECUTING',
                                        'EXECUTED','CANCELLED','FAILED') NOT NULL,

              participant_count    SMALLINT UNSIGNED NOT NULL,
              gross_transfer_count INT UNSIGNED NOT NULL,
              net_transfer_count   INT UNSIGNED NOT NULL,
              gross_volume         BIGINT UNSIGNED NOT NULL,
              net_volume           BIGINT UNSIGNED NOT NULL,

              proposed_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              accept_deadline_at   TIMESTAMP NULL,
              executed_at          TIMESTAMP NULL,
              cancelled_at         TIMESTAMP NULL,
              cancelled_reason     VARCHAR(500) NULL,
              failure_reason       VARCHAR(500) NULL,
              transaction_group    CHAR(36) NULL,
              proposed_by_user_id  BIGINT UNSIGNED NULL,

              created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,

              PRIMARY KEY (id),
              UNIQUE KEY uq_batch_code (batch_code),
              KEY idx_date_status (batch_date, status),
              KEY idx_status_deadline (status, accept_deadline_at),

              CONSTRAINT chk_nb_participants CHECK (participant_count >= 2),
              CONSTRAINT chk_nb_net_le_gross CHECK (net_transfer_count <= gross_transfer_count),
              CONSTRAINT chk_nb_volume_le_gross CHECK (net_volume <= gross_volume),
              CONSTRAINT chk_nb_cancelled_has_reason
                CHECK (status <> 'CANCELLED' OR cancelled_reason IS NOT NULL)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('netting_batches');
    }
};
