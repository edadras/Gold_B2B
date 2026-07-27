<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_role', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');

            $table->unique(['permission_id', 'role_id'], 'uq_permission_role');
            $table->index('role_id', 'idx_permission_role_role');

            $table->foreign('permission_id', 'fk_permission_role_permission')
                ->references('id')->on('permissions')
                ->cascadeOnDelete();
            $table->foreign('role_id', 'fk_permission_role_role')
                ->references('id')->on('roles')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_role');
    }
};
