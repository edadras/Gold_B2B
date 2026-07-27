<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registered push targets (FCM/APNs tokens).
 *
 * A user can have several — phone, tablet, a second phone — and a push goes to
 * all of them, because there is no way to know which one is in their hand.
 *
 * `revoked_at` rather than a delete: when a provider reports a token as invalid
 * we need to remember that it was invalidated, otherwise the next login
 * re-registers it and the failures repeat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_devices', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('organization_id')->nullable();

            $table->enum('platform', ['ANDROID', 'IOS', 'WEB']);
            $table->string('token', 255);
            $table->string('device_name', 100)->nullable();
            $table->string('app_version', 20)->nullable();

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 200)->nullable();

            $table->timestamps();

            $table->unique('token', 'uq_push_token');
            $table->index(['user_id', 'revoked_at'], 'idx_user_active_devices');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_devices');
    }
};
