<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assay laboratories — docs/03-domain/02-gold-lot-assay.md §2.9.
 *
 * accreditation_level drives how much a certificate is trusted: an
 * UNACCREDITED laboratory can only ever produce a DECLARED purity source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name', 191);
            $table->string('license_no', 100);
            $table->string('address', 500)->nullable();
            $table->string('contact_name', 191)->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->string('api_endpoint', 255)->nullable();
            $table->enum('accreditation_level', ['TIER_1', 'TIER_2', 'UNACCREDITED'])
                ->default('UNACCREDITED');
            $table->enum('status', ['ACTIVE', 'SUSPENDED'])->default('ACTIVE');
            $table->unsignedSmallInteger('trust_score')->default(50);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique('license_no', 'uq_lab_license');
            $table->index(['status', 'accreditation_level'], 'idx_lab_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratories');
    }
};
