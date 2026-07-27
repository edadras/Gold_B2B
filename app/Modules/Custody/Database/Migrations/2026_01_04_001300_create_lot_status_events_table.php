<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * lot_status_events — append-only transition log written by LotStateMachine.
 *
 * The generic state-machine pattern in docs/11-appendix/02-state-machines.md
 * §2.1 requires every entity with a state machine to persist its transitions.
 * custody_operations only covers physical movements, so status changes such as
 * RESERVED -> IN_SETTLEMENT need their own log.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE lot_status_events (
              id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              gold_lot_id    BIGINT UNSIGNED NOT NULL,
              from_status    VARCHAR(20) NULL,
              to_status      VARCHAR(20) NOT NULL,
              actor_type     VARCHAR(30) NOT NULL DEFAULT 'USER',
              actor_user_id  BIGINT UNSIGNED NULL,
              reason         VARCHAR(500) NULL,
              reference_type VARCHAR(50) NULL,
              reference_id   BIGINT UNSIGNED NULL,
              metadata       JSON NULL,
              created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

              PRIMARY KEY (id),
              KEY idx_lot_events (gold_lot_id, id),
              KEY idx_lot_event_reference (reference_type, reference_id),

              CONSTRAINT chk_status_actually_changed
                CHECK (from_status IS NULL OR from_status <> to_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_status_events');
    }
};
