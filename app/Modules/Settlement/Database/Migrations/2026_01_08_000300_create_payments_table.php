<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * payments — the evidence trail behind the two-sided confirmation of
 * docs/03-domain/05-settlement.md §5.3 pattern 2.
 *
 * §2.5 of the schema document keeps only the latest reference on the
 * settlement row (payment_reference, payment_declared_at, ...). That is enough
 * for a single clean payment but loses the history the dispute process needs:
 * a rejected declaration, a corrected reference, a partial payment followed by
 * the balance. One row per declaration keeps all of it, and the settlement row
 * still carries the denormalised latest values exactly as §2.5 specifies.
 *
 * The platform does not hold funds in phase 1 (ADR-008): the money moves in the
 * banking system and these rows are the two parties' assertions about it. Only
 * confirmed_at makes rial move in the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE payments (
              id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              settlement_id         BIGINT UNSIGNED NOT NULL,
              payer_organization_id BIGINT UNSIGNED NOT NULL,
              payee_organization_id BIGINT UNSIGNED NOT NULL,

              amount_rial           BIGINT UNSIGNED NOT NULL,
              payment_method        ENUM('BANK_TRANSFER','INTERNAL','NETTED') NOT NULL,
              payment_reference     VARCHAR(100) NULL,
              bank_transaction_id   VARCHAR(100) NULL,
              receipt_path          VARCHAR(255) NULL,

              status                ENUM('DECLARED','CONFIRMED','REJECTED') NOT NULL,

              paid_at               TIMESTAMP NULL,
              declared_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              declared_by_user_id   BIGINT UNSIGNED NULL,
              confirmed_at          TIMESTAMP NULL,
              confirmed_by_user_id  BIGINT UNSIGNED NULL,
              rejected_at           TIMESTAMP NULL,
              rejected_by_user_id   BIGINT UNSIGNED NULL,
              rejection_reason      VARCHAR(500) NULL,
              auto_matched_at       TIMESTAMP NULL,

              transaction_group     CHAR(36) NULL,

              created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                      ON UPDATE CURRENT_TIMESTAMP,

              PRIMARY KEY (id),
              KEY idx_settlement (settlement_id, declared_at),
              KEY idx_payer (payer_organization_id, status),
              KEY idx_payee (payee_organization_id, status),
              KEY idx_reference (payment_reference),

              CONSTRAINT chk_pay_amount_positive CHECK (amount_rial > 0),
              CONSTRAINT chk_pay_parties_differ CHECK (payer_organization_id <> payee_organization_id),
              CONSTRAINT chk_pay_confirmed_has_time
                CHECK (status <> 'CONFIRMED' OR confirmed_at IS NOT NULL),
              CONSTRAINT chk_pay_rejected_has_reason
                CHECK (status <> 'REJECTED' OR rejection_reason IS NOT NULL)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
