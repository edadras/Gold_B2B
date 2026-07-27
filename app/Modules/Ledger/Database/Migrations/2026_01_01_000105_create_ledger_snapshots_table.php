<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/03-domain/03-ledger.md §3.8 — ledger_snapshots.
 *
 * SUM over ledger_entries gets slow as the table grows (≈29M rows/year, §2.9).
 * A snapshot pins the balance at a point in the sequence so a rebuild only has
 * to sum entries with id > last_entry_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_snapshots', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('account_id');
            $table->date('snapshot_date');
            $table->bigInteger('balance');
            $table->unsignedBigInteger('last_entry_id');
            $table->unsignedBigInteger('entry_count');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['account_id', 'snapshot_date'], 'uq_account_date');

            $table->foreign('account_id', 'fk_snapshot_account')
                ->references('id')->on('ledger_accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_snapshots');
    }
};
