<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\OrganizationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only trail of every organisation status transition.
 *
 * Rule 1 of docs/11-appendix/02-state-machines.md §2.14: every transition is
 * recorded with who, when and why. Rows here are never updated or deleted.
 * `from_status` is nullable so the very first row (creation into PENDING) fits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_status_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');

            $table->enum('from_status', OrganizationStatus::values())->nullable();
            $table->enum('to_status', OrganizationStatus::values());

            // SYSTEM for automatic transitions (licence expiry, ledger opening).
            $table->enum('actor_type', ['USER', 'SYSTEM'])->default('USER');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['organization_id', 'id'], 'idx_org_status_events_org');
            $table->index('to_status', 'idx_org_status_events_to');

            $table->foreign('organization_id', 'fk_org_status_events_org')
                ->references('id')->on('organizations')
                ->cascadeOnDelete();
            $table->foreign('actor_user_id', 'fk_org_status_events_actor')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_status_events');
    }
};
