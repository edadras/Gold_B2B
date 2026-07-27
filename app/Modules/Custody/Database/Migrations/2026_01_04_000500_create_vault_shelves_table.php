<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Shelves inside a safe — docs/03-domain/06-custody-vault.md §6.1. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE vault_shelves (
              id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              vault_id      BIGINT UNSIGNED NOT NULL,
              vault_safe_id BIGINT UNSIGNED NOT NULL,
              shelf_code    VARCHAR(10) NOT NULL,
              full_code     VARCHAR(50) NOT NULL,
              status        ENUM('ACTIVE','SEALED','DISABLED') NOT NULL DEFAULT 'ACTIVE',
              created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,

              PRIMARY KEY (id),
              UNIQUE KEY uq_shelf_full_code (full_code),
              UNIQUE KEY uq_shelf_in_safe (vault_safe_id, shelf_code),
              KEY idx_shelf_vault (vault_id),

              CONSTRAINT fk_shelf_safe FOREIGN KEY (vault_safe_id)
                REFERENCES vault_safes (id) ON DELETE RESTRICT,
              CONSTRAINT fk_shelf_vault FOREIGN KEY (vault_id)
                REFERENCES vaults (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_shelves');
    }
};
