<?php

declare(strict_types=1);

use App\Modules\Reputation\Domain\VerificationTier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every tier movement, with its cause.
 *
 * Not in the doc's DDL, but §14.4 requires that a demotion be a compliance
 * officer's decision carrying a reason and a recovery path, and requires the
 * member to be told what happened and why. A decision that is not recorded
 * cannot be explained to the member three months later, nor reviewed if the
 * member appeals — so the reviewer id and the reason are NOT NULL for a
 * demotion, enforced by the application (a promotion has no reviewer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reputation_tier_changes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');

            $table->enum('from_tier', VerificationTier::values())->nullable();
            $table->enum('to_tier', VerificationTier::values());
            $table->enum('direction', ['PROMOTION', 'DEMOTION']);

            // Null for an automatic promotion; mandatory for a demotion.
            $table->unsignedBigInteger('reviewer_user_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamp('promotion_locked_until')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at'], 'idx_tier_change_org');
            $table->index('direction', 'idx_tier_change_direction');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reputation_tier_changes');
    }
};
