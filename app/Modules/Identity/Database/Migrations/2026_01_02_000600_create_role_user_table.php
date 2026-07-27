<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Role assignment. `organization_id` is denormalised onto the pivot on purpose:
 * authorisation checks must verify tenancy *and* permission in the same query
 * (docs/02-architecture/04-security.md §4.3), and a global scope on the parent
 * model alone can be bypassed by a raw query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_user', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('assigned_by_user_id')->nullable();
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();

            $table->unique(['user_id', 'role_id'], 'uq_role_user');
            $table->index(['organization_id', 'role_id'], 'idx_role_user_org_role');

            $table->foreign('user_id', 'fk_role_user_user')
                ->references('id')->on('users')
                ->cascadeOnDelete();
            $table->foreign('role_id', 'fk_role_user_role')
                ->references('id')->on('roles')
                ->cascadeOnDelete();
            $table->foreign('organization_id', 'fk_role_user_org')
                ->references('id')->on('organizations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
    }
};
