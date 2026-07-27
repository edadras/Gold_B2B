<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Boxes inside a shelf — the leaf of the location hierarchy that a GoldLot
 * actually points at. docs/03-domain/06-custody-vault.md §6.1.
 *
 * vault_id is denormalised so "everything in vault V01" is one index lookup
 * rather than a three-way join during reconciliation.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE vault_boxes (
              id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              vault_id          BIGINT UNSIGNED NOT NULL,
              vault_safe_id     BIGINT UNSIGNED NOT NULL,
              vault_shelf_id    BIGINT UNSIGNED NOT NULL,
              box_code          VARCHAR(10) NOT NULL,
              full_code         VARCHAR(50) NOT NULL,
              capacity_gross_mg BIGINT UNSIGNED NULL,
              status            ENUM('ACTIVE','FULL','SEALED','DISABLED')
                                  NOT NULL DEFAULT 'ACTIVE',
              created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,

              PRIMARY KEY (id),
              UNIQUE KEY uq_box_full_code (full_code),
              UNIQUE KEY uq_box_in_shelf (vault_shelf_id, box_code),
              KEY idx_box_vault (vault_id, status),

              CONSTRAINT fk_box_shelf FOREIGN KEY (vault_shelf_id)
                REFERENCES vault_shelves (id) ON DELETE RESTRICT,
              CONSTRAINT fk_box_vault FOREIGN KEY (vault_id)
                REFERENCES vaults (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_boxes');
    }
};
