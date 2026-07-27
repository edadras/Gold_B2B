<?php

declare(strict_types=1);

use App\Modules\Webhook\Domain\DeliveryStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One (event → endpoint) pair per row; the attempts of §3.10 accumulate on it
 * rather than each creating a row. This is what
 * `GET /webhooks/{id}/deliveries` returns (§3.13), field for field.
 *
 * THE UNIQUE KEY IS THE IDEMPOTENCY GUARANTEE. §3.11 tells receivers to
 * deduplicate on `event_id`, and the platform holds up its half: a
 * (webhook_id, event_id) pair can exist only once, so a listener that fires
 * twice, a replayed queue message or a manual replay all land on the SAME row
 * and re-send the SAME id rather than minting a second event that a receiver
 * would have no way of recognising as a duplicate. The id itself is derived
 * from the event's content (Domain\EventIdentity), so it is stable across
 * process restarts too.
 *
 * `payload` is frozen at creation. A retry twenty-four hours later must send
 * byte-identical content — the signature covers the body, and rebuilding it
 * from live data would both invalidate nothing and silently change what the
 * member's books receive.
 *
 * ADR-012: at platform scale this table is the obvious candidate for RANGE
 * partitioning on created_at. Not applied locally (AGENT_BRIEF); the pruning
 * command covers retention until then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('webhook_id');

            // `evt_` + 16 hex characters (§3.8).
            $table->string('event_id', 40);
            $table->string('event_type', 60);

            // The exact §3.8 envelope. Re-serialised deterministically at send
            // time; see DeliverWebhookJob for why the body is built once.
            $table->json('payload');

            $table->enum('status', DeliveryStatus::values())->default(DeliveryStatus::QUEUED->value);
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->unsignedSmallInteger('response_code')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->string('last_error', 500)->nullable();

            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            // §3.11: one row per event per endpoint, forever.
            $table->unique(['webhook_id', 'event_id'], 'uniq_webhook_event');
            // The §3.13 history view, newest first.
            $table->index(['webhook_id', 'id'], 'idx_webhook_id_desc');
            // The sweeper that re-queues rungs of the ladder after a restart.
            $table->index(['status', 'next_retry_at'], 'idx_status_next_retry');
            // Retention pruning.
            $table->index('created_at', 'idx_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
