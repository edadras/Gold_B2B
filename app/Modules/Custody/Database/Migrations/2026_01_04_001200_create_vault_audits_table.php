<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * vault_audits — physical reconciliation runs.
 * docs/03-domain/06-custody-vault.md §6.6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_audits', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('audit_code', 20);
            $table->unsignedBigInteger('vault_id');
            $table->enum('status', ['IN_PROGRESS', 'COMPLETED', 'ESCALATED'])
                ->default('IN_PROGRESS');

            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();

            $table->unsignedInteger('expected_lot_count')->default(0);
            $table->unsignedBigInteger('expected_gross_mg')->default(0);
            $table->unsignedBigInteger('expected_fine_mg')->default(0);
            $table->unsignedInteger('counted_lot_count')->default(0);
            $table->unsignedBigInteger('counted_gross_mg')->default(0);

            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('within_tolerance_count')->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->unsignedInteger('investigation_count')->default(0);
            $table->unsignedInteger('missing_count')->default(0);
            $table->unsignedInteger('unknown_count')->default(0);

            $table->json('variances')->nullable();
            $table->boolean('requires_investigation')->default(false);
            $table->boolean('vault_frozen')->default(false);

            $table->unsignedBigInteger('auditor_user_id');
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique('audit_code', 'uq_audit_code');
            $table->index(['vault_id', 'started_at'], 'idx_audit_vault');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_audits');
    }
};
