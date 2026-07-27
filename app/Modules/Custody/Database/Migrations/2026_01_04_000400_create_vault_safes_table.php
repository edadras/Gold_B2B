<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Safes inside a vault — docs/03-domain/06-custody-vault.md §6.1. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE vault_safes (
              id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              vault_id       BIGINT UNSIGNED NOT NULL,
              safe_code      VARCHAR(10) NOT NULL,
              full_code      VARCHAR(50) NOT NULL,
              type           VARCHAR(50) NULL,
              security_level ENUM('LOW','MEDIUM','HIGH') NOT NULL DEFAULT 'HIGH',
              status         ENUM('ACTIVE','SEALED','DISABLED') NOT NULL DEFAULT 'ACTIVE',
              created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,

              PRIMARY KEY (id),
              UNIQUE KEY uq_safe_full_code (full_code),
              UNIQUE KEY uq_safe_in_vault (vault_id, safe_code),

              CONSTRAINT fk_safe_vault FOREIGN KEY (vault_id)
                REFERENCES vaults (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_safes');
    }
};
