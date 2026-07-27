<?php

declare(strict_types=1);

use App\Modules\Kyc\Domain\KycDecision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only record of every compliance decision (docs §1.5).
 *
 * `notes` is NOT NULL because "هر تصمیم نیازمند یادداشت مکتوب" — a decision
 * without a written reason is not a decision. `automated_checks` stores the
 * machine verdicts the officer saw at the time, so a later dispute can be
 * judged on the information actually available.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_reviews', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('kyc_profile_id');
            $table->unsignedBigInteger('reviewer_user_id');

            $table->enum('decision', KycDecision::values());
            $table->string('notes', 2000);
            $table->json('automated_checks')->nullable();
            $table->json('missing_items')->nullable();

            $table->timestamp('reviewed_at')->useCurrent();

            $table->index(['organization_id', 'id'], 'idx_kyc_reviews_org');
            $table->index('reviewer_user_id', 'idx_kyc_reviews_reviewer');

            $table->foreign('organization_id', 'fk_kyc_reviews_org')
                ->references('id')->on('organizations')
                ->cascadeOnDelete();
            $table->foreign('kyc_profile_id', 'fk_kyc_reviews_profile')
                ->references('id')->on('kyc_profiles')
                ->cascadeOnDelete();
            $table->foreign('reviewer_user_id', 'fk_kyc_reviews_reviewer')
                ->references('id')->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_reviews');
    }
};
