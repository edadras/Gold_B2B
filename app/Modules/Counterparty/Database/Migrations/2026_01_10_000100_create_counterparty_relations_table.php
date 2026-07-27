<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The bilateral standing relation between two members (docs/03-domain/10-counterparty.md §10.2).
 *
 * One row per *ordered* pair, so every relation is stored twice — once from each
 * side. The two rows are mirror images: the symmetry invariant
 *
 *     relation(A,B).gold_balance_mg == -relation(B,A).gold_balance_mg
 *     relation(A,B).rial_balance    == -relation(B,A).rial_balance
 *
 * is checked nightly by `counterparty:reconcile`. Sign convention: positive
 * means the counterparty owes us.
 *
 * No foreign key to `organizations`: the Counterparty module must migrate and
 * run whether or not Identity is present in a given deployment slice, and the
 * DDL in the design doc carries none either. Orphan detection is part of the
 * reconcile command instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counterparty_relations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('counterparty_org_id');

            // Signed on purpose: positive = the counterparty owes us. Storing an
            // absolute value plus a direction flag would break the accumulating
            // upsert in RelationService, which is what makes concurrent
            // settlements safe.
            $table->bigInteger('gold_balance_mg')->default(0);
            $table->bigInteger('rial_balance')->default(0);

            // Credit *we* grant *them*. Unsigned: a negative limit is meaningless.
            $table->unsignedBigInteger('gold_credit_limit_mg')->default(0);
            $table->unsignedBigInteger('rial_credit_limit')->default(0);

            $table->timestamp('first_trade_at')->nullable();
            $table->timestamp('last_trade_at')->nullable();
            $table->unsignedInteger('total_trade_count')->default(0);
            $table->unsignedBigInteger('total_volume_mg')->default(0);
            $table->unsignedInteger('overdue_count')->default(0);
            $table->unsignedInteger('dispute_count')->default(0);

            $table->boolean('is_trusted')->default(false);
            $table->boolean('is_blocked')->default(false);
            $table->boolean('auto_accept_otc')->default(false);
            $table->text('internal_note')->nullable();

            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['organization_id', 'counterparty_org_id'], 'uq_pair');
            $table->index('counterparty_org_id', 'idx_counterparty');
            // Concentration analysis scans one side's positive balances.
            $table->index(['organization_id', 'gold_balance_mg'], 'idx_org_gold');
        });

        // A member cannot be its own counterparty; the whole module assumes two
        // distinct sides and the symmetry invariant is undefined otherwise.
        DB::statement(
            'ALTER TABLE counterparty_relations
             ADD CONSTRAINT ck_relation_not_self CHECK (organization_id <> counterparty_org_id)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('counterparty_relations');
    }
};
