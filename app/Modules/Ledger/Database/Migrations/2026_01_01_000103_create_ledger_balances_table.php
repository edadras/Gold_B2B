<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/04-data/02-schema-mysql.md §2.2 — ledger_balances.
 *
 * A CACHE, not the source of truth: every value here is Σ(ledger_entries.amount)
 * for the account and can be rebuilt at any time (rebuildBalance / ledger:reconcile).
 * The row is what writers take FOR UPDATE, so it is also the serialisation point
 * that makes the race in §3.10 impossible.
 *
 * No CHECK (balance >= 0): the rule depends on the account's bucket, and MySQL
 * CHECK cannot subquery (§2.2 note). The doc offers a trigger; this migration
 * relies on the application guard in LedgerService::writeEntry() plus the
 * nightly reconciliation instead, so the constraint lives in one place and can
 * raise a typed DomainException rather than SQLSTATE 45000.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_balances', function (Blueprint $table): void {
            $table->unsignedBigInteger('account_id')->primary();
            $table->bigInteger('balance')->default(0);
            $table->unsignedBigInteger('last_entry_id')->nullable();
            $table->unsignedBigInteger('entry_count')->default(0);
            $table->unsignedBigInteger('version')->default(0);   // optimistic lock counter
            $table->timestamp('updated_at', 6)->useCurrent()->useCurrentOnUpdate();

            $table->foreign('account_id', 'fk_balance_account')
                ->references('id')->on('ledger_accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_balances');
    }
};
