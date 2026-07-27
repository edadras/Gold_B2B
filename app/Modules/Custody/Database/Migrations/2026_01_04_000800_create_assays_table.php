<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * assays — docs/04-data/02-schema-mysql.md §2.3.
 *
 * Certificates are never updated in place: a re-assay writes a new row and
 * flips the previous one to SUPERSEDED with superseded_by_id set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assays', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('assay_code', 20);                 // AS-00004421
            $table->unsignedBigInteger('gold_lot_id');
            $table->string('certificate_no', 100);
            $table->unsignedBigInteger('laboratory_id');
            $table->enum('method', ['FIRE_ASSAY', 'XRF', 'ICP', 'OTHER']);

            $table->unsignedBigInteger('gross_weight_mg');
            $table->unsignedSmallInteger('purity_x10');
            $table->unsignedBigInteger('fine_weight_mg');

            $table->timestamp('assayed_at');
            $table->timestamp('valid_until')->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->char('qr_token', 64);

            $table->enum('status', ['VALID', 'SUPERSEDED', 'DISPUTED', 'REVOKED']);
            $table->unsignedBigInteger('superseded_by_id')->nullable();
            $table->timestamp('verified_by_lab_at')->nullable();
            $table->unsignedBigInteger('recorded_by_user_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique('assay_code', 'uq_assay_code');
            $table->unique('qr_token', 'uq_qr_token');
            $table->unique(['laboratory_id', 'certificate_no'], 'uq_lab_certificate');
            $table->index(['gold_lot_id', 'status'], 'idx_lot');
            $table->index('laboratory_id', 'idx_lab');
        });

        DB::statement('ALTER TABLE assays ADD CONSTRAINT chk_assay_purity_range CHECK (purity_x10 <= 10000)');
        DB::statement('ALTER TABLE assays ADD CONSTRAINT chk_assay_fine_le_gross CHECK (fine_weight_mg <= gross_weight_mg)');
        DB::statement('ALTER TABLE assays ADD CONSTRAINT chk_assay_gross_positive CHECK (gross_weight_mg > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('assays');
    }
};
