<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * lot_status_events — append-only transition log written by LotStateMachine.
 *
 * The generic state-machine pattern in docs/11-appendix/02-state-machines.md
 * §2.1 requires every entity with a state machine to persist its transitions;
 * custody_operations only covers physical movements, so status changes such as
 * RESERVED -> IN_SETTLEMENT need their own log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lot_status_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('gold_lot_id');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('actor_type', 30)->default('USER');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['gold_lot_id', 'id'], 'idx_lot_events');
            $table->index(['reference_type', 'reference_id'], 'idx_lot_event_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_status_events');
    }
};
