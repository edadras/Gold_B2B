<?php

declare(strict_types=1);

use App\Modules\Notification\Domain\Priority;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The in-app notification record (docs §15.3). This row *is* the in-app
 * delivery; the `notification_deliveries` table only tracks the external
 * channels that can fail.
 *
 * Two columns are additions to the doc's DDL, both required by §15.4 rule 3:
 * `aggregate_count` and `aggregate_window_start`, which turn "seven identical
 * lines in the feed" into one collapsed row that keeps counting.
 *
 * Not partitioned locally — see ADR-012 for the production partitioning plan on
 * `created_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            // NULL means every user of the organisation.
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('code', 50);
            $table->string('category', 30);
            $table->enum('priority', Priority::values());

            $table->string('title', 200);
            $table->string('body', 1000);

            $table->string('action_type', 50)->nullable();
            $table->json('action_payload')->nullable();

            $table->string('subject_type', 50)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // §15.4 rule 3: set only on a collapsed row.
            $table->unsignedInteger('aggregate_count')->nullable();
            $table->timestamp('aggregate_window_start')->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at'], 'idx_org_created');
            $table->index(['user_id', 'read_at'], 'idx_user_unread');
            $table->index('code', 'idx_code');
            // Serves both the deduplication lookup (§15.4 rule 4) and the
            // same-code window scan the batching rule needs.
            $table->index(['user_id', 'code', 'subject_id'], 'idx_dedup');
            $table->index(['user_id', 'code', 'created_at'], 'idx_batch_window');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
