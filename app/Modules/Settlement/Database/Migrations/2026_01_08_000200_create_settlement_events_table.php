<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * settlement_events — docs/04-data/02-schema-mysql.md §2.5, second table.
 *
 * The append-only transition log required by appendix §2.14 rule 1: every
 * status change records who, when and why. SettlementStateMachine writes one
 * row per transition inside the same database transaction as the status update,
 * so a status can never exist without its justification.
 *
 * ADR-012 partitioning by occurred_at deliberately omitted locally.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE settlement_events (
              id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              settlement_id     BIGINT UNSIGNED NOT NULL,
              from_status       VARCHAR(30) NULL,
              to_status         VARCHAR(30) NOT NULL,
              actor_type        ENUM('USER','SYSTEM','PLATFORM_STAFF') NOT NULL,
              actor_user_id     BIGINT UNSIGNED NULL,
              reason            VARCHAR(500) NULL,
              transaction_group CHAR(36) NULL,
              metadata          JSON NULL,
              occurred_at       TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              PRIMARY KEY (id),
              KEY idx_settlement_time (settlement_id, occurred_at),
              KEY idx_to_status (to_status, occurred_at),

              CONSTRAINT chk_stl_evt_user_actor
                CHECK (actor_type <> 'USER' OR actor_user_id IS NOT NULL)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_events');
    }
};
