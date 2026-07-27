<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refiners — docs/03-domain/02-gold-lot-assay.md §2.9.
 *
 * average_loss_bps is the rolling melt loss used to predict the yield of a
 * MELT before it happens, and to spot a refiner whose losses are drifting.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE refiners (
              id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              name               VARCHAR(191) NOT NULL,
              license_no         VARCHAR(100) NOT NULL,
              address            VARCHAR(500) NULL,
              average_loss_bps   INT UNSIGNED NOT NULL DEFAULT 0,
              total_processed_mg BIGINT UNSIGNED NOT NULL DEFAULT 0,
              variance_history   JSON NULL,
              status             ENUM('ACTIVE','SUSPENDED') NOT NULL DEFAULT 'ACTIVE',
              created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,

              PRIMARY KEY (id),
              UNIQUE KEY uq_refiner_license (license_no),
              KEY idx_refiner_status (status),

              CONSTRAINT chk_refiner_loss_bps CHECK (average_loss_bps <= 10000)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('refiners');
    }
};
