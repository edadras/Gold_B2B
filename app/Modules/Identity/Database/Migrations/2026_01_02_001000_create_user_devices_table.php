<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Known devices, so an unfamiliar one triggers the "new login" notification of
 * docs/02-architecture/04-security.md §4.2 step 6, and so a refresh token can
 * be bound to a device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_devices', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');

            $table->string('device_id', 128);
            $table->string('name', 191)->nullable();
            $table->string('platform', 32)->nullable();
            $table->string('app_version', 32)->nullable();
            // Public key registered for device-bound transaction signing.
            $table->text('public_key')->nullable();

            $table->boolean('is_trusted')->default(false);
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'device_id'], 'uq_user_devices');
            $table->index('device_id', 'idx_user_devices_device');

            $table->foreign('user_id', 'fk_user_devices_user')
                ->references('id')->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');
    }
};
