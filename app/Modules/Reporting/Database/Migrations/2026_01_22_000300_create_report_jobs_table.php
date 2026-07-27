<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/03-domain/15-notification-reporting.md §15.8 — asynchronous reports.
 *
 * A light report runs inline; a heavy one is queued, written to storage under a
 * random name, and handed back as a signed link that expires
 * («گزارش تولیدشده در S3 با نام تصادفی و لینک امضاشده»، «لینک موقت ۲۴ ساعت»).
 *
 * `download_token` is that random name: the file path is never guessable from
 * the job id, so knowing a job exists does not let anyone read another member's
 * report. The token is unique and carries its own expiry, checked on every
 * download rather than only at generation.
 *
 * Every generation is auditable — «هر تولید گزارش در Audit ثبت می‌شود» — which
 * is why the requesting user and the exact parameters are stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('requested_by_user_id')->nullable();

            $table->string('report_type', 40);
            $table->string('format', 10);

            $table->date('range_from');
            $table->date('range_to');
            $table->json('parameters')->nullable();

            $table->enum('status', ['QUEUED', 'RUNNING', 'COMPLETED', 'FAILED', 'EXPIRED'])
                ->default('QUEUED');

            // True when the report was small enough to run in the request.
            $table->boolean('ran_inline')->default(false);
            $table->unsignedInteger('estimated_rows')->default(0);
            $table->unsignedInteger('row_count')->default(0);

            $table->string('file_path', 500)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->char('checksum', 64)->nullable();

            $table->char('download_token', 64)->nullable()->unique();
            $table->timestamp('token_expires_at')->nullable();

            $table->string('error', 500)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at'], 'idx_org_created');
            $table->index(['status', 'created_at'], 'idx_status_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_jobs');
    }
};
