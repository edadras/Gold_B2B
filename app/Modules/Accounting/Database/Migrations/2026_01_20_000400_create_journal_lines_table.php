<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/03-domain/09-accounting.md §9.2 — journal_lines.
 *
 * `line_set` implements the decision recorded in §9.3: a voucher holds two
 * independently balanced sets rather than one mixed set, because a rial-only
 * account (۱۱۰۳) cannot balance the gold column debited to ۱۱۱۰. RIAL lines
 * carry only rial, GOLD lines only milligrams, and each set sums to zero on its
 * own — which is why the entry-wide invariant holds by construction.
 *
 * CHECK constraints enforce that split at the storage layer too; MariaDB
 * honours them (AGENT_BRIEF "Environment").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('journal_entry_id');
            $table->unsignedBigInteger('organization_id');   // denormalised for reporting

            $table->string('account_code', 10);
            $table->enum('line_set', ['RIAL', 'GOLD']);

            // تفصیلی — which counterparty this line concerns, when it has one.
            $table->unsignedBigInteger('counterparty_org_id')->nullable();

            $table->unsignedBigInteger('debit_rial')->default(0);
            $table->unsignedBigInteger('credit_rial')->default(0);

            $table->unsignedBigInteger('debit_fine_mg')->default(0);
            $table->unsignedBigInteger('credit_fine_mg')->default(0);

            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('line_no');

            $table->index('journal_entry_id', 'idx_entry');
            $table->index(['account_code', 'journal_entry_id'], 'idx_account');
            $table->index('counterparty_org_id', 'idx_counterparty');
            $table->index(['organization_id', 'account_code'], 'idx_org_account');
        });

        // A line is never both sides of the same column.
        DB::statement(
            'ALTER TABLE journal_lines ADD CONSTRAINT chk_single_rial_side '
            .'CHECK (debit_rial = 0 OR credit_rial = 0)'
        );
        DB::statement(
            'ALTER TABLE journal_lines ADD CONSTRAINT chk_single_gold_side '
            .'CHECK (debit_fine_mg = 0 OR credit_fine_mg = 0)'
        );

        // A RIAL line carries no weight; a GOLD line carries no money.
        DB::statement(
            "ALTER TABLE journal_lines ADD CONSTRAINT chk_line_set_purity "
            ."CHECK ((line_set = 'RIAL' AND debit_fine_mg = 0 AND credit_fine_mg = 0) "
            ."OR (line_set = 'GOLD' AND debit_rial = 0 AND credit_rial = 0))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
    }
};
