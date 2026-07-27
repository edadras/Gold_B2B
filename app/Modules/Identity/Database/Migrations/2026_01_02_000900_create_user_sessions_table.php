<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Login session audit (docs/02-architecture/04-security.md §4.2 step 5).
 *
 * Separate from the framework `sessions` table: that one is a cache of
 * serialised session payloads and gets garbage-collected, this one is the
 * durable record of who logged in from where, kept for compliance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('organization_id');

            // SHA-256 of the issued access token: lets us revoke and correlate
            // without storing anything that could be replayed.
            $table->char('token_hash', 64)->nullable();
            $table->string('device_id', 128)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('geo_country', 2)->nullable();
            $table->string('geo_city', 100)->nullable();

            $table->boolean('two_factor_satisfied')->default(false);
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 191)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'started_at'], 'idx_user_sessions_user');
            $table->index('organization_id', 'idx_user_sessions_organization');
            $table->index('token_hash', 'idx_user_sessions_token');

            $table->foreign('user_id', 'fk_user_sessions_user')
                ->references('id')->on('users')
                ->cascadeOnDelete();
            $table->foreign('organization_id', 'fk_user_sessions_org')
                ->references('id')->on('organizations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
