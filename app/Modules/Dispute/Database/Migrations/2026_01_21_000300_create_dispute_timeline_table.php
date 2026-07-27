<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/03-domain/13-dispute.md §13.5 — dispute_timeline.
 *
 * APPEND-ONLY. This is the evidentiary record of the case: §2.14 rule 1
 * requires every transition to be written here with who, when and why, and the
 * §13.9 screen is a direct rendering of these rows. A timeline that could be
 * edited would be worth nothing in an argument about what happened.
 *
 * Microsecond precision, because several rows are written in the same request
 * (open → hold → notify) and their order is the story.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispute_timeline', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('dispute_id');

            $table->enum('actor_type', ['CLAIMANT', 'RESPONDENT', 'MEDIATOR', 'SYSTEM']);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('actor_org_id')->nullable();

            $table->string('action', 100);
            $table->text('message')->nullable();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();

            $table->timestamp('occurred_at', 6);

            $table->index(['dispute_id', 'occurred_at'], 'idx_dispute_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_timeline');
    }
};
