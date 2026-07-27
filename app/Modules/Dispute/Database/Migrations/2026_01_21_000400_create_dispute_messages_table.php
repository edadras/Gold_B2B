<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The negotiation room of §13.6 مرحله ۲ — «فضای گفت‌وگوی ثبت‌شده».
 *
 * Not in the §13.5 schema listing, but the process it describes cannot exist
 * without it: the parties talk, every message is recorded, the operator can
 * read the lot, and a settlement proposal that the other side accepts becomes a
 * binding verdict with no operator involvement. That is where §13.10 expects
 * 30% of disputes to end, so the proposal is a first-class row with its own
 * numbers rather than free text a human has to interpret.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispute_messages', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('dispute_id');

            $table->unsignedBigInteger('sender_org_id');
            $table->unsignedBigInteger('sender_user_id');

            $table->enum('message_type', [
                'MESSAGE', 'SETTLEMENT_PROPOSAL', 'PROPOSAL_ACCEPTED', 'PROPOSAL_REJECTED',
            ])->default('MESSAGE');

            $table->text('body');

            // Signed, and only meaningful on a SETTLEMENT_PROPOSAL: the amount
            // the sender offers to transfer to the other party.
            $table->bigInteger('proposed_gold_mg')->default(0);
            $table->bigInteger('proposed_rial')->default(0);

            // Set on the proposal row when it is accepted or rejected.
            $table->unsignedBigInteger('responds_to_message_id')->nullable();
            $table->string('resolution', 20)->nullable();

            $table->timestamp('created_at', 6);

            $table->index(['dispute_id', 'created_at'], 'idx_dispute_time');
            $table->index(['dispute_id', 'message_type'], 'idx_dispute_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_messages');
    }
};
