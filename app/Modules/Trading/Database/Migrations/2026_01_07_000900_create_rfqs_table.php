<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rfqs — request for quote (docs/03-domain/04-trading.md §4.7).
 *
 * `recipient_org_ids` is JSON rather than a join table: it is written once with
 * the request and only ever read back whole, and the SELECTED visibility mode
 * is the only one that populates it.
 *
 * `accepted_mg` tracks partial acceptance (rule 4 of §4.7 — the requester may
 * accept several quotes up to the requested volume).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE rfqs (
              id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              rfq_code           VARCHAR(20) NOT NULL,
              instrument_id      BIGINT UNSIGNED NOT NULL,
              organization_id    BIGINT UNSIGNED NOT NULL,
              created_by_user_id BIGINT UNSIGNED NOT NULL,

              side               ENUM('BUY','SELL') NOT NULL,
              quantity_mg        BIGINT UNSIGNED NOT NULL,
              accepted_mg        BIGINT UNSIGNED NOT NULL DEFAULT 0,
              min_purity_x10     SMALLINT UNSIGNED NULL,

              settlement_type    ENUM('INSTANT','T0','T1','T2','T3','ON_ACCOUNT') NOT NULL,
              delivery_type      ENUM('VAULT_TRANSFER','PHYSICAL_HANDOVER',
                                      'CUSTODY_CHANGE','NETTED') NOT NULL,

              visibility         ENUM('SELECTED','ALL_QUALIFIED','ANONYMOUS') NOT NULL,
              recipient_org_ids  JSON NULL,
              allow_partial      TINYINT(1) NOT NULL DEFAULT 1,

              status             ENUM('OPEN','QUOTED','PARTIALLY_ACCEPTED','ACCEPTED',
                                      'CANCELLED','EXPIRED') NOT NULL,

              expires_at         TIMESTAMP NOT NULL,
              closed_at          TIMESTAMP NULL,

              metadata           JSON NULL,
              created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,

              PRIMARY KEY (id),
              UNIQUE KEY uq_rfq_code (rfq_code),
              KEY idx_rfq_org (organization_id, status),
              KEY idx_rfq_expires (status, expires_at),
              KEY idx_rfq_instrument (instrument_id, status),

              CONSTRAINT chk_rfq_accepted_le_quantity CHECK (accepted_mg <= quantity_mg),
              CONSTRAINT chk_rfq_quantity_positive CHECK (quantity_mg > 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('rfqs');
    }
};
