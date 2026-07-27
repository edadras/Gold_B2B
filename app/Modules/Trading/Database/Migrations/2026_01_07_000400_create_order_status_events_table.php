<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * order_status_events — append-only transition log for the order state machine.
 *
 * Rule 1 of docs/11-appendix/02-state-machines.md §2.14: every transition is
 * recorded with who, when and why. Follows the shape Custody used for
 * lot_status_events so the two logs read the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE order_status_events (
              id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              order_id       BIGINT UNSIGNED NOT NULL,
              from_status    VARCHAR(20) NULL,
              to_status      VARCHAR(20) NOT NULL,
              actor_type     VARCHAR(30) NOT NULL DEFAULT 'USER',
              actor_user_id  BIGINT UNSIGNED NULL,
              reason         VARCHAR(500) NULL,
              reference_type VARCHAR(50) NULL,
              reference_id   BIGINT UNSIGNED NULL,
              metadata       JSON NULL,
              created_at     TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

              PRIMARY KEY (id),
              KEY idx_order_events (order_id, id),
              KEY idx_order_event_reference (reference_type, reference_id),

              CONSTRAINT chk_order_status_changed
                CHECK (from_status IS NULL OR from_status <> to_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_events');
    }
};
