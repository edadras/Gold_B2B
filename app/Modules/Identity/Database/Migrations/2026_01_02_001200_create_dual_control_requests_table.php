<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\DualControlStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generic maker/checker requests (docs/01-product/01-personas-roles.md §1.6).
 *
 * The hard rule `maker_user_id != checker_user_id` is enforced twice: by the
 * CHECK constraint below (MariaDB 10.11 honours CHECK) and by
 * DualControlService, because a constraint violation is a 500 and a domain
 * exception is a 422.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dual_control_requests', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id')->nullable();

            $table->string('action', 96);
            $table->json('payload');
            $table->string('payload_hash', 64);

            $table->unsignedBigInteger('maker_user_id');
            $table->timestamp('requested_at')->useCurrent();
            $table->string('maker_note', 500)->nullable();

            $table->unsignedBigInteger('checker_user_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('checker_note', 500)->nullable();

            $table->enum('status', DualControlStatus::values())
                ->default(DualControlStatus::PENDING->value);
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'action'], 'idx_dual_control_status_action');
            $table->index('organization_id', 'idx_dual_control_organization');
            $table->index('maker_user_id', 'idx_dual_control_maker');

            $table->foreign('organization_id', 'fk_dual_control_org')
                ->references('id')->on('organizations')
                ->restrictOnDelete();
            $table->foreign('maker_user_id', 'fk_dual_control_maker')
                ->references('id')->on('users')
                ->restrictOnDelete();
            $table->foreign('checker_user_id', 'fk_dual_control_checker')
                ->references('id')->on('users')
                ->restrictOnDelete();
        });

        // Belt and braces: the database itself refuses a self-approval.
        DB::statement(
            'ALTER TABLE dual_control_requests
             ADD CONSTRAINT chk_dual_control_distinct_actors
             CHECK (checker_user_id IS NULL OR checker_user_id <> maker_user_id)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('dual_control_requests');
    }
};
