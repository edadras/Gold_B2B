<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authorised signatories of a legal entity (صاحبان امضا) — who may bind the
 * company, and whether they may do so alone or jointly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signatories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');

            $table->string('full_name', 191);
            $table->binary('national_id_enc')->nullable();
            $table->char('national_id_hash', 64)->nullable();
            $table->string('position', 191)->nullable();

            // JOINT signatories must sign together; SOLE may bind alone.
            $table->enum('signature_authority', ['SOLE', 'JOINT'])->default('JOINT');
            $table->string('scope', 500)->nullable();

            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('document_id')->nullable();
            $table->timestamps();

            $table->index('organization_id', 'idx_signatories_organization');
            $table->index('national_id_hash', 'idx_signatories_national_id');

            $table->foreign('organization_id', 'fk_signatories_org')
                ->references('id')->on('organizations')
                ->cascadeOnDelete();
            $table->foreign('document_id', 'fk_signatories_document')
                ->references('id')->on('documents')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signatories');
    }
};
