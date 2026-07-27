<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * cross_settlements — docs/03-domain/05-settlement.md §5.7.
 *
 * A rial obligation discharged with gold (or the reverse) needs a record of its
 * own, separate from the two settlements it touches, because §5.7's four
 * requirements are all properties of the AGREEMENT rather than of either
 * obligation:
 *
 *   · «توافق صریح دو طرف»            → debtor_agreed_at / creditor_agreed_at
 *   · «قیمت مرجع مورد قبول هر دو»     → agreed_rate_rial, written at proposal
 *   · «ثبت شفاف نرخ تبدیل استفاده‌شده» → the same column, immutable afterwards
 *   · «تأیید انطباق شرعی/حقوقی»       → compliance_note, carried with the row
 *
 * agreed_rate_rial is the column that matters most. Reading the rate from the
 * market at execution time would let the timing of a click change how much
 * gold a member owes; storing it at agreement time makes the number a term of
 * the deal, and the executing code never consults a price source at all.
 *
 * The applied / discharged / remaining columns are written once at execution
 * and are the audit trail of the conversion: rounding_rial is the dust that
 * whole-milligram arithmetic left behind, posted to ROUNDING_DIFFERENCE so the
 * ledger still conserves.
 *
 * Raw DDL rather than a Blueprint, for the CHECK constraints — same reasoning
 * as 2026_01_08_000100_create_settlements_table.php. No FKs into another
 * module's tables (AGENT_BRIEF rule 7); settlement ids are plain columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE cross_settlements (
              id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              cross_code             VARCHAR(20) NOT NULL,

              rial_settlement_id     BIGINT UNSIGNED NOT NULL,
              gold_settlement_id     BIGINT UNSIGNED NOT NULL,

              debtor_org_id          BIGINT UNSIGNED NOT NULL,
              creditor_org_id        BIGINT UNSIGNED NOT NULL,

              agreed_rate_rial       BIGINT UNSIGNED NOT NULL,
              rial_obligation_rial   BIGINT UNSIGNED NOT NULL,
              gold_obligation_mg     BIGINT UNSIGNED NOT NULL,

              gold_applied_mg        BIGINT UNSIGNED NOT NULL DEFAULT 0,
              rial_discharged_rial   BIGINT UNSIGNED NOT NULL DEFAULT 0,
              gold_value_rial        BIGINT UNSIGNED NOT NULL DEFAULT 0,
              rounding_rial          BIGINT UNSIGNED NOT NULL DEFAULT 0,
              gold_remaining_mg      BIGINT UNSIGNED NOT NULL DEFAULT 0,
              rial_remaining_rial    BIGINT UNSIGNED NOT NULL DEFAULT 0,

              status                 ENUM('PROPOSED','AGREED','EXECUTED','REJECTED') NOT NULL,

              proposed_by_org_id     BIGINT UNSIGNED NOT NULL,
              proposed_by_user_id    BIGINT UNSIGNED NULL,
              debtor_agreed_at       TIMESTAMP NULL,
              debtor_agreed_by_user_id   BIGINT UNSIGNED NULL,
              creditor_agreed_at     TIMESTAMP NULL,
              creditor_agreed_by_user_id BIGINT UNSIGNED NULL,

              compliance_note        VARCHAR(500) NULL,
              rejected_reason        VARCHAR(500) NULL,
              rejected_at            TIMESTAMP NULL,

              executed_at            TIMESTAMP NULL,
              transaction_group      CHAR(36) NULL,

              created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,

              PRIMARY KEY (id),
              UNIQUE KEY uq_cross_code (cross_code),
              KEY idx_cross_rial_settlement (rial_settlement_id, status),
              KEY idx_cross_gold_settlement (gold_settlement_id, status),
              KEY idx_cross_debtor (debtor_org_id, status),
              KEY idx_cross_creditor (creditor_org_id, status),

              CONSTRAINT chk_cross_parties_differ CHECK (debtor_org_id <> creditor_org_id),
              CONSTRAINT chk_cross_settlements_differ
                CHECK (rial_settlement_id <> gold_settlement_id),
              CONSTRAINT chk_cross_rate_positive CHECK (agreed_rate_rial > 0),
              CONSTRAINT chk_cross_obligations_positive
                CHECK (rial_obligation_rial > 0 AND gold_obligation_mg > 0),
              CONSTRAINT chk_cross_applied_within_obligation
                CHECK (gold_applied_mg <= gold_obligation_mg
                       AND rial_discharged_rial <= rial_obligation_rial)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('cross_settlements');
    }
};
