<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raised flags and their review trail. docs/03-domain/12-aml-compliance.md §12.5.
 *
 * Tipping-off (§12.10): nothing in this table may ever be surfaced to the
 * member it concerns. Access control lives in the read layer, not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aml_flags', function (Blueprint $table): void {
            $table->id();
            $table->string('rule_code', 50);
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->enum('severity', ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL']);
            $table->enum('status', [
                'OPEN', 'UNDER_REVIEW', 'ENHANCED_REVIEW',
                'CLEARED', 'FALSE_POSITIVE', 'ESCALATED', 'ACTION_TAKEN',
            ])->default('OPEN');
            $table->string('summary', 500);
            $table->json('context');
            $table->string('subject_type', 50)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->timestamp('raised_at');
            $table->unsignedBigInteger('assigned_to_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->string('action_taken', 200)->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->string('report_reference', 100)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'idx_org_status');
            $table->index(['severity', 'status'], 'idx_severity_status');
            $table->index('raised_at', 'idx_raised');
            $table->index('rule_code', 'idx_rule_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aml_flags');
    }
};
