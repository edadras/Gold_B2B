<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * netting_positions — docs/03-domain/05-settlement.md §5.6.
 *
 * One row per participant per batch. net_position is signed (positive =
 * creditor), so the column is a plain BIGINT and not UNSIGNED, and invariant
 * N2 (net_position = gross_in − gross_out) is enforced by a CHECK rather than
 * trusted to the calculator.
 *
 * N1 (Σ net_position = 0) spans rows and so cannot be a CHECK; NettingService
 * asserts it before the batch is written and again before it is executed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE netting_positions (
              id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              batch_id            BIGINT UNSIGNED NOT NULL,
              organization_id     BIGINT UNSIGNED NOT NULL,

              gross_in            BIGINT UNSIGNED NOT NULL,
              gross_out           BIGINT UNSIGNED NOT NULL,
              net_position        BIGINT NOT NULL,
              obligation_count    INT UNSIGNED NOT NULL DEFAULT 0,

              accepted_at         TIMESTAMP NULL,
              accepted_by_user_id BIGINT UNSIGNED NULL,
              rejected_at         TIMESTAMP NULL,
              rejected_by_user_id BIGINT UNSIGNED NULL,
              rejection_reason    VARCHAR(500) NULL,

              created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,

              PRIMARY KEY (id),
              UNIQUE KEY uq_batch_org (batch_id, organization_id),
              KEY idx_org (organization_id),
              KEY idx_pending (batch_id, accepted_at, rejected_at),

              CONSTRAINT chk_np_net_is_in_minus_out
                CHECK (net_position = CAST(gross_in AS SIGNED) - CAST(gross_out AS SIGNED)),
              CONSTRAINT chk_np_not_both_answers
                CHECK (accepted_at IS NULL OR rejected_at IS NULL),
              CONSTRAINT chk_np_rejection_has_reason
                CHECK (rejected_at IS NULL OR rejection_reason IS NOT NULL)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('netting_positions');
    }
};
