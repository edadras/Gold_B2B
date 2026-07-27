<?php

declare(strict_types=1);

use App\Modules\Webhook\Domain\WebhookStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One registered endpoint per row — docs/05-api/03-realtime-webhooks.md §3.7.
 *
 * ───────────────────────── ON STORING THE SECRET ─────────────────────────
 *
 * §3.7 shows the secret returned exactly once, with «این تنها بار نمایش secret
 * است». That is a rule about the API surface, and it is enforced absolutely:
 * no endpoint in this module ever emits `secret` again, not in the list, not in
 * the detail view, not in a delivery record.
 *
 * It is NOT, and cannot be, a rule that the column holds a one-way hash. §3.9
 * signs each delivery with `HMAC_SHA256(webhook_secret, …)` and the member
 * verifies it with the same secret using the sample code in the document. HMAC
 * is symmetric: the signer must possess the same bytes as the verifier. A
 * bcrypt or SHA-256 digest cannot produce that signature, so a webhook whose
 * secret was only hashed could never be signed at all.
 *
 * The resolution, which is what Stripe, GitHub and every other webhook provider
 * does:
 *
 *   · `secret_encrypted` holds the secret under AES-256-GCM with the
 *     application key (Laravel's `encrypted` cast). A database dump alone does
 *     not yield signing keys; an attacker needs APP_KEY as well.
 *   · `secret_hash` holds SHA-256 of the secret. Nothing needs the plaintext to
 *     answer "is this the secret you were given?" — support and the rotation
 *     path compare hashes in constant time and never decrypt.
 *   · `secret_last_four` is a display hint so a member can tell two webhooks
 *     apart without either of the above being exposed.
 *
 * Rotating (§3.13 `POST /webhooks/{id}/rotate-secret`) overwrites all three and
 * stamps `secret_rotated_at`; the old secret stops being valid immediately.
 *
 * ─────────────────────────── FAILURE BOOKKEEPING ───────────────────────────
 *
 * §3.10's two thresholds need two different clocks, so both are stored:
 * `consecutive_failures` counts attempts (seven → FAILING) and
 * `failing_since` is the wall-clock instant the current failure streak began
 * (seventy-two hours → DISABLED). A success clears both. Keeping the streak on
 * the webhook rather than deriving it from the deliveries table means the
 * decision is one row read inside the same transaction that records the attempt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');

            // 500 matches OutboundUrlGuard::MAX_URL_LENGTH.
            $table->string('url', 500);

            // The subscribed slice of the §3.12 catalogue. JSON rather than a
            // pivot table: it is read whole on every dispatch, written whole on
            // every update, and never queried by element.
            $table->json('events');

            $table->text('secret_encrypted');
            $table->char('secret_hash', 64);
            $table->char('secret_last_four', 4);
            $table->timestamp('secret_rotated_at')->nullable();

            $table->enum('status', WebhookStatus::values())->default(WebhookStatus::ACTIVE->value);
            $table->string('description', 255)->nullable();

            // §3.10 counters.
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedBigInteger('total_failures')->default(0);
            $table->unsignedBigInteger('total_deliveries')->default(0);
            $table->timestamp('failing_since')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('disabled_at')->nullable();

            $table->timestamps();

            // The dispatch hot path: "active endpoints of organisation N".
            $table->index(['organization_id', 'status'], 'idx_org_status');
            // The 72-hour sweep.
            $table->index(['status', 'failing_since'], 'idx_status_failing_since');
            // Constant-time "does this secret belong to a webhook" without
            // decrypting anything.
            $table->index('secret_hash', 'idx_secret_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhooks');
    }
};
