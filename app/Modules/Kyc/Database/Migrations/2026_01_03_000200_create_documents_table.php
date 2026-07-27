<?php

declare(strict_types=1);

use App\Modules\Kyc\Domain\DocumentStatus;
use App\Modules\Kyc\Domain\DocumentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uploaded evidence. The file itself never lives in the database (docs §1.3
 * storage rules) — only an unguessable UUID path on the configured disk, the
 * SHA-256 of the bytes for tamper detection, and the review verdict.
 *
 * Created before business_licenses and bank_accounts because both reference it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');

            $table->enum('type', DocumentType::values());
            $table->string('disk', 32)->default('local');
            $table->string('storage_path', 500);
            $table->string('original_filename', 255)->nullable();
            $table->char('file_hash', 64);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');

            $table->enum('status', DocumentStatus::values())->default(DocumentStatus::PENDING->value);
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by_user_id')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->date('expires_at')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'type'], 'idx_documents_org_type');
            $table->index('status', 'idx_documents_status');
            // Same bytes uploaded twice is worth spotting during review.
            $table->index('file_hash', 'idx_documents_hash');

            $table->foreign('organization_id', 'fk_documents_org')
                ->references('id')->on('organizations')
                ->cascadeOnDelete();
            $table->foreign('uploaded_by_user_id', 'fk_documents_uploader')
                ->references('id')->on('users')
                ->nullOnDelete();
            $table->foreign('verified_by_user_id', 'fk_documents_verifier')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
