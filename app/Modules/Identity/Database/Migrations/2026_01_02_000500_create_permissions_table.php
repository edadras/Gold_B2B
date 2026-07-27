<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per App\Modules\Identity\Domain\Permission case. The enum is the
 * source of truth; this table exists so the admin UI can list and group
 * permissions and so the pivot has something to reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name', 96);
            $table->string('group', 32);
            $table->string('description', 500)->nullable();
            $table->boolean('requires_dual_control')->default(false);
            $table->timestamps();

            $table->unique('name', 'uq_permissions_name');
            $table->index('group', 'idx_permissions_group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
