<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/03-domain/13-dispute.md §13.5 — dispute_evidences.
 *
 * `file_hash` is what makes a document evidence rather than an attachment: the
 * hash is recorded when it is submitted, so a file swapped afterwards can be
 * detected. Evidence is never edited or removed once submitted — an operator
 * looking at a case must see what the parties actually filed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispute_evidences', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('dispute_id');

            $table->unsignedBigInteger('submitted_by_org_id');
            $table->unsignedBigInteger('submitted_by_user_id');

            $table->enum('evidence_type', [
                'DOCUMENT', 'PHOTO', 'VIDEO', 'ASSAY_REPORT',
                'BANK_STATEMENT', 'WITNESS_STATEMENT', 'SYSTEM_LOG',
            ]);

            $table->unsignedBigInteger('document_id')->nullable();
            $table->text('description');
            $table->char('file_hash', 64)->nullable();
            $table->timestamp('submitted_at');

            $table->index('dispute_id', 'idx_dispute');
            $table->index(['dispute_id', 'submitted_by_org_id'], 'idx_dispute_org');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_evidences');
    }
};
