<?php

declare(strict_types=1);

use App\Modules\Kyc\Domain\BankAccountStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Member bank accounts. The IBAN is encrypted with a blind index over it, the
 * same pattern as national ids: settlement needs to look an IBAN up, nobody
 * needs to browse them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');

            $table->binary('iban_enc');
            $table->char('iban_hash', 64);
            $table->string('bank_name', 100);
            $table->string('bank_code', 8)->nullable();
            $table->string('account_holder_name', 191);
            $table->string('account_no', 64)->nullable();

            $table->boolean('is_primary')->default(false);
            $table->enum('status', BankAccountStatus::values())
                ->default(BankAccountStatus::PENDING->value);
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by_user_id')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->unsignedBigInteger('document_id')->nullable();

            $table->timestamps();

            // The same IBAN registered under two members is an AML signal, so
            // the blind index is unique platform-wide.
            $table->unique('iban_hash', 'uq_bank_accounts_iban');
            $table->index(['organization_id', 'is_primary'], 'idx_bank_accounts_org_primary');

            $table->foreign('organization_id', 'fk_bank_accounts_org')
                ->references('id')->on('organizations')
                ->cascadeOnDelete();
            $table->foreign('document_id', 'fk_bank_accounts_document')
                ->references('id')->on('documents')
                ->nullOnDelete();
            $table->foreign('verified_by_user_id', 'fk_bank_accounts_verifier')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
