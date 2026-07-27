<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\AuthorityType;
use App\Modules\Identity\Domain\RepresentativeStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authorised representative (نماینده مجاز) — docs/03-domain/01-identity-kyc.md §1.7.
 *
 * The shop owner rarely trades in person; an employee does. A TRADER user
 * without a valid representative record may not trade, expiry suspends trading
 * access automatically, and every trade records the representative_id for
 * accountability.
 *
 * `document_id` intentionally has no foreign key: `documents` belongs to the
 * Kyc module and migrates after Identity. Referential integrity is enforced in
 * the application layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('representatives', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('full_name', 191);
            $table->binary('national_id_enc')->nullable();
            $table->char('national_id_hash', 64)->nullable();

            $table->enum('authority_type', AuthorityType::values());
            // Daily authority ceiling in milligrams of fine gold. Integer only —
            // never a float on a financial path (AGENT_BRIEF rule 1).
            $table->unsignedBigInteger('daily_limit_mg')->nullable();

            $table->date('valid_from');
            $table->date('valid_until')->nullable();

            $table->unsignedBigInteger('document_id')->nullable();
            $table->enum('status', RepresentativeStatus::values())
                ->default(RepresentativeStatus::PENDING->value);

            $table->timestamps();

            $table->index('organization_id', 'idx_representatives_organization');
            $table->index('user_id', 'idx_representatives_user');
            $table->index(['status', 'valid_until'], 'idx_representatives_expiry');

            $table->foreign('organization_id', 'fk_representatives_org')
                ->references('id')->on('organizations')
                ->restrictOnDelete();
            $table->foreign('user_id', 'fk_representatives_user')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('representatives');
    }
};
