<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * settlements — transcribed from docs/04-data/02-schema-mysql.md §2.5.
 *
 * Raw DDL rather than a Blueprint: the CHECK constraints below are part of the
 * table definition and Laravel's Blueprint cannot express them, so issuing them
 * as follow-up ALTERs would rebuild the table once per constraint. Custody set
 * that precedent (see 2026_01_04_000700_create_gold_lots_table.php).
 *
 * No FKs to organizations / users / trades: those tables belong to Identity and
 * Trading, and this module migrates independently of both (AGENT_BRIEF rule 7).
 *
 * Deviations from §2.5, all additive:
 *   locked_gold_mg / locked_cash_rial / held_bucket
 *       what this settlement currently holds in IN_SETTLEMENT (or IN_DISPUTE).
 *       The §5.2 side-effect table has to release exactly what was locked when
 *       a settlement is cancelled or disputed, and after a partial settlement
 *       the locked amount no longer equals fine_weight_mg / cash_amount_rial.
 *   settled_at            start of the 24 h objection window (§5.2, SETTLED → COMPLETED).
 *   escalation_level      how far up the §5.5 overdue ladder this row has climbed.
 *   penalty_accrued_at    when penalty accrual started (T+2h).
 *   reversal_* columns    dual-control audit of §5.8; the row is never deleted.
 *   partial_*             option A of §5.4.
 *
 * ADR-012 calls for RANGE PARTITION BY created_at once this table is large.
 * Not applied locally (AGENT_BRIEF: "skip partitioning locally").
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE settlements (
              id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              settlement_code           VARCHAR(20) NOT NULL,
              trade_id                  BIGINT UNSIGNED NOT NULL,
              settlement_type           ENUM('INSTANT','T0','T1','T2','T3','ON_ACCOUNT') NOT NULL,

              gold_deliverer_org_id     BIGINT UNSIGNED NOT NULL,
              gold_receiver_org_id      BIGINT UNSIGNED NOT NULL,
              cash_payer_org_id         BIGINT UNSIGNED NOT NULL,
              cash_receiver_org_id      BIGINT UNSIGNED NOT NULL,

              fine_weight_mg            BIGINT UNSIGNED NOT NULL,
              cash_amount_rial          BIGINT UNSIGNED NOT NULL,
              buyer_fee_rial            BIGINT UNSIGNED NOT NULL DEFAULT 0,
              seller_fee_rial           BIGINT UNSIGNED NOT NULL DEFAULT 0,

              delivery_method           ENUM('VAULT_TRANSFER','PHYSICAL_HANDOVER',
                                             'CUSTODY_CHANGE','NETTED') NOT NULL,
              allocated_lot_ids         JSON NULL,
              gold_transferred_at       TIMESTAMP NULL,
              gold_confirmed_by_user_id BIGINT UNSIGNED NULL,

              payment_method            ENUM('BANK_TRANSFER','INTERNAL','NETTED') NOT NULL,
              payment_reference         VARCHAR(100) NULL,
              bank_transaction_id       VARCHAR(100) NULL,
              payment_declared_at       TIMESTAMP NULL,
              payment_confirmed_at      TIMESTAMP NULL,
              auto_matched_at           TIMESTAMP NULL,

              gold_reservation_entry_id BIGINT UNSIGNED NULL,
              cash_reservation_entry_id BIGINT UNSIGNED NULL,

              locked_gold_mg            BIGINT UNSIGNED NOT NULL DEFAULT 0,
              locked_cash_rial          BIGINT UNSIGNED NOT NULL DEFAULT 0,
              held_bucket               ENUM('IN_SETTLEMENT','IN_DISPUTE') NULL,

              deadline_at               TIMESTAMP NOT NULL,
              overdue_since             TIMESTAMP NULL,
              penalty_rial              BIGINT UNSIGNED NOT NULL DEFAULT 0,
              penalty_accrued_at        TIMESTAMP NULL,
              escalation_level          TINYINT UNSIGNED NOT NULL DEFAULT 0,
              settled_at                TIMESTAMP NULL,
              completed_at              TIMESTAMP NULL,

              status                    ENUM('CREATED','ASSETS_LOCKED','PAYMENT_PENDING',
                                             'PAYMENT_DECLARED','PAYMENT_CONFIRMED',
                                             'GOLD_TRANSFERRING','SETTLED','COMPLETED',
                                             'OVERDUE','DEFAULTED','CANCELLED','DISPUTED',
                                             'REVERSED','NETTING_QUEUE') NOT NULL,

              netting_batch_id          BIGINT UNSIGNED NULL,
              dispute_id                BIGINT UNSIGNED NULL,
              parent_settlement_id      BIGINT UNSIGNED NULL,
              partial_of_rial           BIGINT UNSIGNED NULL,

              cancelled_reason          VARCHAR(500) NULL,
              reversed_at               TIMESTAMP NULL,
              reversal_reason           VARCHAR(500) NULL,
              reversal_requested_by_user_id BIGINT UNSIGNED NULL,
              reversal_approved_by_user_id  BIGINT UNSIGNED NULL,

              version                   BIGINT UNSIGNED NOT NULL DEFAULT 0,
              created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                          ON UPDATE CURRENT_TIMESTAMP,

              PRIMARY KEY (id),
              UNIQUE KEY uq_settlement_code (settlement_code),
              KEY idx_payer_pending (cash_payer_org_id, status, deadline_at),
              KEY idx_receiver_pending (cash_receiver_org_id, status, deadline_at),
              KEY idx_deliverer (gold_deliverer_org_id, status),
              KEY idx_deadline (status, deadline_at),
              KEY idx_trade (trade_id),
              KEY idx_netting (netting_batch_id),
              KEY idx_parent (parent_settlement_id),

              CONSTRAINT chk_stl_parties_differ CHECK (gold_deliverer_org_id <> gold_receiver_org_id),
              CONSTRAINT chk_stl_cash_parties_differ CHECK (cash_payer_org_id <> cash_receiver_org_id),
              CONSTRAINT chk_stl_weight_positive CHECK (fine_weight_mg > 0),
              CONSTRAINT chk_stl_locked_gold CHECK (locked_gold_mg <= fine_weight_mg),
              CONSTRAINT chk_stl_locked_cash CHECK (locked_cash_rial <= cash_amount_rial + buyer_fee_rial)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};
