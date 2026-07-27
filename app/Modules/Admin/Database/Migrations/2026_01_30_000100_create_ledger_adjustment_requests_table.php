<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/08-frontend-web/01-web-panels.md §1.10 — the manual ledger adjustment.
 *
 * The screen writes here, never to `ledger_entries`. A row becomes a ledger
 * posting only after a second user with a different role approves it, and the
 * `maker != checker` rule lives in the database as well as in
 * Admin\Domain\DualControlPolicy — docs/01-product/01-personas-roles.md §1.6
 * calls for both ("در سطح دیتابیس با constraint و در سطح اپلیکیشن با Policy").
 *
 * Rows are never deleted: a withdrawn request is CANCELLED, a refused one is
 * REJECTED. The panel exposes no route that removes one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_adjustment_requests', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('reference', 40)->unique();

            // Not a foreign key, for the same reason ledger_accounts holds
            // none: Admin must not wire a constraint into Identity's tables.
            $table->unsignedBigInteger('organization_id');

            $table->enum('asset_type', ['GOLD', 'RIAL']);

            // Signed. Negative reduces the member's balance. Integer only —
            // milligrams of fine gold or rial, never a decimal.
            $table->bigInteger('amount');

            $table->string('offset_account', 50);

            // The §1.10 form demands a detailed reason; 50 characters is the
            // documented floor and is checked in the request object too.
            $table->text('reason');

            $table->string('supporting_document_path', 500);
            $table->string('supporting_document_name', 255)->nullable();
            $table->char('supporting_document_hash', 64)->nullable();

            $table->enum('status', [
                'PENDING_APPROVAL', 'APPROVED', 'POSTED', 'REJECTED', 'CANCELLED',
            ])->default('PENDING_APPROVAL');

            $table->unsignedBigInteger('requested_by_user_id');
            $table->timestamp('requested_at')->useCurrent();

            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('decision_note', 1000)->nullable();

            $table->timestamp('posted_at')->nullable();
            $table->char('transaction_group', 36)->nullable();
            $table->unsignedBigInteger('member_entry_id')->nullable();
            $table->unsignedBigInteger('offset_entry_id')->nullable();

            $table->timestamps();

            $table->index(['status', 'requested_at'], 'idx_status_requested');
            $table->index('organization_id', 'idx_adjustment_org');
            $table->index('requested_by_user_id', 'idx_adjustment_maker');
        });

        // Maker-checker, at the level an application bug cannot reach past.
        // NULL approved_by_user_id makes the comparison UNKNOWN, which a CHECK
        // constraint accepts — exactly right for a request not yet decided.
        DB::statement(
            'ALTER TABLE ledger_adjustment_requests
             ADD CONSTRAINT chk_adjustment_maker_is_not_checker
             CHECK (approved_by_user_id IS NULL OR approved_by_user_id <> requested_by_user_id)'
        );

        // A posted adjustment must carry both legs and the group they share.
        DB::statement(
            "ALTER TABLE ledger_adjustment_requests
             ADD CONSTRAINT chk_adjustment_posted_is_complete
             CHECK (status <> 'POSTED' OR (transaction_group IS NOT NULL
                    AND member_entry_id IS NOT NULL AND offset_entry_id IS NOT NULL))"
        );

        // Amount zero is not an adjustment, it is a mistake.
        DB::statement(
            'ALTER TABLE ledger_adjustment_requests
             ADD CONSTRAINT chk_adjustment_amount_nonzero CHECK (amount <> 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_adjustment_requests');
    }
};
