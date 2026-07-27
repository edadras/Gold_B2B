<?php

declare(strict_types=1);

use App\Modules\Kyc\Domain\LicenseStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guild trading licence (جواز کسب). Kept as history — a renewal is a new row,
 * so the audit trail shows exactly which licence was valid when.
 *
 * `reminder_sent_*` columns make the 30/10/1-day reminder ladder of docs §1.6
 * idempotent: the scheduled command can run every hour without spamming.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_licenses', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');

            $table->string('license_no', 64);
            $table->string('issuing_union', 191);
            $table->string('activity_type', 191)->nullable();
            $table->string('premises_address', 500)->nullable();

            $table->date('issued_at');
            $table->date('expires_at');

            $table->enum('status', LicenseStatus::values())->default(LicenseStatus::PENDING->value);
            $table->unsignedBigInteger('document_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by_user_id')->nullable();

            $table->boolean('reminder_sent_30d')->default(false);
            $table->boolean('reminder_sent_10d')->default(false);
            $table->boolean('reminder_sent_1d')->default(false);
            $table->boolean('expiry_enforced')->default(false);

            $table->timestamps();

            $table->unique(['organization_id', 'license_no'], 'uq_business_licenses_org_no');
            $table->index(['status', 'expires_at'], 'idx_business_licenses_expiry');

            $table->foreign('organization_id', 'fk_business_licenses_org')
                ->references('id')->on('organizations')
                ->cascadeOnDelete();
            $table->foreign('document_id', 'fk_business_licenses_document')
                ->references('id')->on('documents')
                ->nullOnDelete();
            $table->foreign('verified_by_user_id', 'fk_business_licenses_verifier')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_licenses');
    }
};
