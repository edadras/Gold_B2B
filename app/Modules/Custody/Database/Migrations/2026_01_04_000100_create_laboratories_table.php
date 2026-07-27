<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assay laboratories — docs/03-domain/02-gold-lot-assay.md §2.9.
 *
 * accreditation_level drives how far a certificate is trusted: an UNACCREDITED
 * laboratory can only ever produce a DECLARED purity source.
 *
 * Every migration in this module is a single DDL statement. The module's
 * tables carry CHECK constraints and composite foreign keys that Blueprint
 * cannot express in one go, and issuing follow-up ALTERs would rebuild each
 * table several times.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE laboratories (
              id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              name                VARCHAR(191) NOT NULL,
              license_no          VARCHAR(100) NOT NULL,
              address             VARCHAR(500) NULL,
              contact_name        VARCHAR(191) NULL,
              contact_phone       VARCHAR(20) NULL,
              api_endpoint        VARCHAR(255) NULL,
              accreditation_level ENUM('TIER_1','TIER_2','UNACCREDITED')
                                    NOT NULL DEFAULT 'UNACCREDITED',
              status              ENUM('ACTIVE','SUSPENDED') NOT NULL DEFAULT 'ACTIVE',
              trust_score         SMALLINT UNSIGNED NOT NULL DEFAULT 50,
              created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,

              PRIMARY KEY (id),
              UNIQUE KEY uq_lab_license (license_no),
              KEY idx_lab_status (status, accreditation_level),

              CONSTRAINT chk_lab_trust_score CHECK (trust_score <= 100)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratories');
    }
};
