<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/04-data/02-schema-mysql.md §2.2 — ledger_accounts.
 *
 * One row per (organisation, asset, metal, bucket). organization_id = 0 is
 * reserved for the system accounts of docs/03-domain/03-ledger.md §3.3, which
 * are additionally distinguished by system_account_code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // 0 = system. Deliberately NOT a foreign key: the ledger must not
            // hold a FK into the Identity module's tables (AGENT_BRIEF rule 7),
            // and org 0 has no organizations row.
            $table->unsignedBigInteger('organization_id');

            $table->enum('asset_type', ['GOLD', 'RIAL']);
            $table->enum('metal_type', ['GOLD', 'SILVER', 'PLATINUM'])->nullable();
            $table->enum('bucket', ['AVAILABLE', 'RESERVED', 'IN_SETTLEMENT', 'IN_DISPUTE', 'PAYABLE']);
            $table->string('system_account_code', 50)->nullable();
            $table->char('currency', 3)->nullable()->default('IRR');
            $table->boolean('allows_negative')->default(false);
            $table->enum('status', ['ACTIVE', 'FROZEN', 'CLOSED'])->default('ACTIVE');
            $table->timestamp('created_at')->useCurrent();

            // The doc declares UNIQUE (organization_id, asset_type, metal_type,
            // bucket), but that key cannot be used literally:
            //   1. MySQL/MariaDB treat NULLs as distinct, so it would not
            //      deduplicate RIAL accounts (metal_type is NULL there);
            //   2. §2.7 seeds six distinct GOLD/GOLD/AVAILABLE system accounts
            //      for org 0, which the literal key would reject.
            // A stored generated column gives the intended semantics — the same
            // tuple plus system_account_code, with NULLs folded to a sentinel.
            $table->string('account_key', 160)->storedAs(
                "concat(organization_id, ':', asset_type, ':', coalesce(metal_type, '-'),"
                ." ':', bucket, ':', coalesce(system_account_code, '-'))"
            );

            $table->unique('account_key', 'uq_org_asset_bucket_code');
            $table->index(['organization_id', 'asset_type', 'metal_type', 'bucket'], 'idx_org_asset_bucket');
            $table->index('organization_id', 'idx_organization');
            $table->index('system_account_code', 'idx_system_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_accounts');
    }
};
