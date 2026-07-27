<?php

declare(strict_types=1);

use App\Modules\Kyc\Domain\KycStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One dossier per member. Holds the tax profile fields of docs §1.1 plus the
 * review scheduling that docs §1.6 drives off the risk band.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_profiles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');

            $table->enum('status', KycStatus::values())->default(KycStatus::DRAFT->value);

            // TaxProfile
            $table->string('economic_code', 32)->nullable();
            $table->string('tax_tracking_code', 64)->nullable();
            $table->boolean('tax_registered')->default(false);

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('review_started_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->string('last_decision_note', 1000)->nullable();

            // Periodic re-review: LOW 36m / MEDIUM 24m / HIGH 12m (docs §1.6).
            $table->date('next_review_due_at')->nullable();
            $table->unsignedSmallInteger('submission_count')->default(0);

            $table->timestamps();

            $table->unique('organization_id', 'uq_kyc_profiles_org');
            $table->index('status', 'idx_kyc_profiles_status');
            $table->index('next_review_due_at', 'idx_kyc_profiles_review_due');

            $table->foreign('organization_id', 'fk_kyc_profiles_org')
                ->references('id')->on('organizations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_profiles');
    }
};
