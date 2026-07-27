<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\ComplianceState;
use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Identity\Domain\OrganizationType;
use App\Modules\Identity\Domain\RiskLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The member organisation — the owner of every gold and rial balance in the
 * system. DDL follows docs/04-data/02-schema-mysql.md §2.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->enum('type', OrganizationType::values());
            $table->enum('status', OrganizationStatus::values())
                ->default(OrganizationStatus::PENDING->value);

            $table->string('display_name', 191);
            $table->string('legal_name', 191)->nullable();

            // Identity: ciphertext plus an HMAC blind index so uniqueness and
            // lookup work without decrypting. See Domain\BlindIndex.
            $table->binary('national_id_enc')->nullable();
            $table->char('national_id_hash', 64)->nullable();
            $table->binary('legal_id_enc')->nullable();
            $table->char('legal_id_hash', 64)->nullable();
            $table->string('registration_no', 50)->nullable();
            $table->date('established_at')->nullable();

            // Guild / trade details
            $table->string('union_name', 191)->nullable();
            $table->string('city', 100);
            $table->string('province', 100)->nullable();
            $table->string('market_name', 191)->nullable();
            $table->string('address', 500)->nullable();
            $table->char('postal_code', 10)->nullable();

            // Contact
            $table->string('phone', 20)->nullable();
            $table->string('mobile', 20);
            $table->string('email', 191)->nullable();
            $table->string('website', 191)->nullable();

            $table->enum('risk_level', RiskLevel::values())->default(RiskLevel::MEDIUM->value);
            $table->enum('compliance_state', ComplianceState::values())
                ->default(ComplianceState::NORMAL->value);

            $table->timestamp('activated_at')->nullable();
            $table->timestamp('restricted_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('restriction_reason', 500)->nullable();

            $table->timestamps();

            $table->unique('national_id_hash', 'uq_organizations_national_id');
            $table->unique('legal_id_hash', 'uq_organizations_legal_id');
            $table->unique('mobile', 'uq_organizations_mobile');
            $table->index('status', 'idx_organizations_status');
            $table->index('city', 'idx_organizations_city');
            $table->index('risk_level', 'idx_organizations_risk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
