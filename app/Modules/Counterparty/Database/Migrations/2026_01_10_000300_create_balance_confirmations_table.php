<?php

declare(strict_types=1);

use App\Modules\Counterparty\Domain\ConfirmationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mutual balance confirmation (docs/03-domain/10-counterparty.md §10.4).
 *
 * A cheap step between "our numbers differ" and a formal dispute: A states the
 * balance it holds as of a date, B either agrees or answers with its own
 * figure, and the system shows the delta together with the movements of the
 * period so a bookkeeping slip can be found without arbitration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balance_confirmations', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // The side that asked.
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('counterparty_org_id');

            $table->timestamp('period_start')->nullable();
            $table->timestamp('as_of');

            // Requester's figures, in the requester's sign convention
            // (positive = counterparty owes requester).
            $table->bigInteger('requester_gold_mg');
            $table->bigInteger('requester_rial');

            // Responder's figures, restated into the requester's convention so
            // the delta is a plain subtraction rather than a sign puzzle.
            $table->bigInteger('responder_gold_mg')->nullable();
            $table->bigInteger('responder_rial')->nullable();

            $table->enum('status', ConfirmationStatus::values())
                ->default(ConfirmationStatus::PENDING->value);

            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->unsignedBigInteger('responded_by_user_id')->nullable();
            $table->string('requester_note', 500)->nullable();
            $table->string('responder_note', 500)->nullable();

            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'counterparty_org_id', 'as_of'], 'idx_confirmation_pair');
            $table->index(['counterparty_org_id', 'status'], 'idx_confirmation_inbox');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_confirmations');
    }
};
