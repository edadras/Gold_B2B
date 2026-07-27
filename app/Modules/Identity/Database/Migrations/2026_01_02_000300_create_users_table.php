<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\UserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Human login. Every user belongs to exactly one organisation; the assets
 * belong to the organisation, not to the user.
 *
 * This table is owned by the Identity module — the framework's default
 * `users` migration was removed in favour of this one, because logins here are
 * keyed on mobile number, not email.
 *
 * DDL follows docs/04-data/02-schema-mysql.md §2.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('branch_id')->nullable();

            $table->string('full_name', 191);
            $table->string('mobile', 20);
            $table->string('email', 191)->nullable();
            $table->binary('national_id_enc')->nullable();
            $table->char('national_id_hash', 64)->nullable();

            $table->string('password_hash', 255);
            $table->timestamp('password_changed_at')->nullable();

            $table->binary('two_factor_secret_enc')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->text('two_factor_recovery_enc')->nullable();
            // Highest TOTP counter already spent, so a code cannot be replayed
            // inside its own 30-second window.
            $table->unsignedBigInteger('two_factor_last_counter')->nullable();

            $table->enum('status', UserStatus::values())->default(UserStatus::PENDING->value);

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->unsignedTinyInteger('failed_login_count')->default(0);
            $table->timestamp('locked_until')->nullable();

            $table->rememberToken();
            $table->timestamps();

            $table->unique('mobile', 'uq_users_mobile');
            $table->index('organization_id', 'idx_users_organization');
            $table->index('branch_id', 'idx_users_branch');
            $table->index('status', 'idx_users_status');
            $table->index('national_id_hash', 'idx_users_national_id');

            $table->foreign('organization_id', 'fk_users_org')
                ->references('id')->on('organizations')
                ->restrictOnDelete();
            $table->foreign('branch_id', 'fk_users_branch')
                ->references('id')->on('branches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
