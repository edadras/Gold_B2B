<?php

declare(strict_types=1);

use App\Modules\Notification\Domain\Channel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per external delivery attempt (docs §15.3).
 *
 * `destination` holds a *hash*, not the phone number or push token: this table
 * is read by support staff during incident triage, and a member's mobile number
 * has no business being visible there. A hash is still enough to answer "did
 * this go to the same device as last time".
 *
 * SKIPPED is a first-class status. A user with no push device or a preference
 * switched off produces a row saying so, because "we never sent it" is a
 * question support gets asked and silence is not an answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('notification_id');

            $table->enum('channel', Channel::externalValues());
            $table->string('destination', 255);
            $table->enum('status', ['QUEUED', 'SENT', 'DELIVERED', 'FAILED', 'SKIPPED']);

            $table->string('provider_ref', 100)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error', 500)->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('notification_id', 'idx_notification');
            $table->index('status', 'idx_status');
            $table->index(['channel', 'created_at'], 'idx_channel_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
