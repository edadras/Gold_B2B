<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamp('occurred_at', 6)->useCurrent();
            $table->enum('actor_type', ['user', 'system', 'platform_staff', 'api_client']);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('action', 100);
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->binary('ip_address')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->char('session_id', 36)->nullable();
            $table->char('request_id', 36)->nullable();
            $table->enum('result', ['success', 'failure', 'denied'])->default('success');
            $table->string('failure_reason', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->char('prev_hash', 64)->nullable();
            $table->char('row_hash', 64);

            $table->index(['organization_id', 'occurred_at'], 'idx_audit_org_time');
            $table->index(['actor_id', 'occurred_at'], 'idx_audit_actor_time');
            $table->index(['subject_type', 'subject_id'], 'idx_audit_subject');
            $table->index(['action', 'occurred_at'], 'idx_audit_action_time');
            $table->index('request_id', 'idx_audit_request');
        });

        // Production additionally applies:
        //   REVOKE UPDATE, DELETE ON audit_logs FROM the application user
        //   PARTITION BY RANGE (UNIX_TIMESTAMP(occurred_at))  -- see ADR-012
        // Neither is applied locally: the first needs a privileged connection,
        // the second is deferred per ADR-012.
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
