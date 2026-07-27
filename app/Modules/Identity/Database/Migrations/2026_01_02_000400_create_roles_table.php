<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named roles from docs/01-product/01-personas-roles.md §1.3.
 *
 * Roles are global rows (one OWNER row, not one per organisation); the tenancy
 * lives on the role_user pivot, which carries organization_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name', 64);
            $table->string('label', 191);
            $table->string('description', 500)->nullable();
            // Platform roles belong to the operator's staff and must never be
            // granted inside a member organisation.
            $table->boolean('is_platform_role')->default(false);
            $table->timestamps();

            $table->unique('name', 'uq_roles_name');
            $table->index('is_platform_role', 'idx_roles_platform');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
