<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Vaults — docs/03-domain/06-custody-vault.md §6.1 and §6.7 (insurance). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE vaults (
              id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              vault_code              VARCHAR(10) NOT NULL,
              name                    VARCHAR(191) NOT NULL,
              address                 VARCHAR(500) NULL,
              operator_organization_id BIGINT UNSIGNED NULL,
              capacity_fine_mg        BIGINT UNSIGNED NULL,

              insurance_policy_no     VARCHAR(100) NULL,
              insurer_name            VARCHAR(191) NULL,
              coverage_amount_rial    BIGINT UNSIGNED NULL,
              coverage_expires_at     DATE NULL,
              liability_terms         TEXT NULL,

              status                  ENUM('ACTIVE','SUSPENDED','FROZEN','CLOSED')
                                        NOT NULL DEFAULT 'ACTIVE',
              created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,

              PRIMARY KEY (id),
              UNIQUE KEY uq_vault_code (vault_code),
              KEY idx_vault_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('vaults');
    }
};
