<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * lot_lineage — the parent/child graph that is never deleted.
 * docs/03-domain/02-gold-lot-assay.md §2.7, schema §2.3.
 *
 * chk_no_self_parent is the cheapest possible cycle guard; the depth cap in
 * LineageService::ancestorsOf handles the rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE lot_lineage (
              id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              parent_lot_id   BIGINT UNSIGNED NOT NULL,
              child_lot_id    BIGINT UNSIGNED NOT NULL,
              operation       ENUM('SPLIT','MERGE','MELT','REASSAY') NOT NULL,
              operation_id    BIGINT UNSIGNED NOT NULL,
              created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

              PRIMARY KEY (id),
              UNIQUE KEY uq_parent_child (parent_lot_id, child_lot_id),
              KEY idx_child (child_lot_id),

              CONSTRAINT chk_no_self_parent CHECK (parent_lot_id <> child_lot_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_lineage');
    }
};
