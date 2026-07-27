<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional sub-unit of a member (شعبه). Branches never own balances — assets
 * always belong to the organisation (docs/01-product/01-personas-roles.md §1.1)
 * — they exist to scope users and to attribute activity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');

            $table->string('code', 32);
            $table->string('name', 191);
            $table->string('city', 100)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('phone', 20)->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'uq_branches_org_code');
            $table->index('organization_id', 'idx_branches_organization');

            $table->foreign('organization_id', 'fk_branches_org')
                ->references('id')->on('organizations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
