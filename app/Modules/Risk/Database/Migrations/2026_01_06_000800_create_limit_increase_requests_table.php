<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/03-domain/11-risk-credit.md §11.8 — automatic prerequisite screening
 * followed by a human decision. Not listed in the module brief's table list but
 * required to persist the workflow; see the module report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('limit_increase_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('requested_by_user_id');
            $table->string('limit_type', 40);
            $table->unsignedBigInteger('current_value');
            $table->unsignedBigInteger('requested_value');
            $table->text('justification')->nullable();
            $table->enum('status', [
                'AUTO_REJECTED', 'PENDING_REVIEW', 'APPROVED',
                'APPROVED_WITH_COLLATERAL', 'REJECTED',
            ]);
            $table->json('failed_prerequisites');
            $table->json('prerequisite_snapshot');
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->unsignedBigInteger('required_collateral_rial')->nullable();
            $table->timestamp('next_review_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'idx_org_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limit_increase_requests');
    }
};
