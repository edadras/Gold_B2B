<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Application;

use App\Modules\Kyc\Domain\DocumentStatus;
use App\Modules\Kyc\Domain\DocumentType;
use App\Modules\Kyc\Infrastructure\Models\Document;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Stores KYC evidence according to the rules in
 * docs/03-domain/01-identity-kyc.md §1.3:
 *
 *   - the file never goes in the database, only a path;
 *   - the path is a random UUID, so it cannot be guessed or enumerated;
 *   - SHA-256 of the bytes is recorded so tampering is detectable;
 *   - max 10 MB, JPG / PNG / PDF only.
 *
 * PRODUCTION GAP — deliberately not implemented here:
 *   * EXIF stripping. Phone photos of national cards carry GPS coordinates of
 *     the member's home; those must be removed before the bytes are persisted.
 *     Belongs right here, between validation and put().
 *   * Malware scanning. Every accepted file must go through a scanner (ClamAV
 *     or the platform AV gateway) BEFORE it is written to durable storage, and
 *     the upload must be rejected, not quarantined, on a positive.
 *   * The production disk is S3 with SSE-KMS and access is only ever granted
 *     through 5-minute pre-signed URLs, each download written to the audit log.
 *     Local development uses the plain `local` disk.
 */
final class DocumentService
{
    public const MAX_SIZE_BYTES = 10 * 1024 * 1024;

    /** @var list<string> */
    public const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'application/pdf'];

    /**
     * @param  array<string, mixed>  $meta  expires_at, original_filename
     */
    public function store(
        int $organizationId,
        DocumentType $type,
        UploadedFile $file,
        ?int $uploadedByUserId = null,
        array $meta = [],
    ): Document {
        $mime = (string) $file->getMimeType();
        $size = (int) $file->getSize();

        $this->assertAcceptable($mime, $size);

        $contents = (string) file_get_contents($file->getRealPath());

        // <<< EXIF stripping and malware scanning belong here, on $contents,
        //     before anything is written. See the class docblock.

        $hash = hash('sha256', $contents);
        $disk = $this->diskName();
        $path = $this->buildPath($organizationId, $type, $file);

        Storage::disk($disk)->put($path, $contents);

        return DB::transaction(function () use ($organizationId, $type, $disk, $path, $hash, $mime, $size, $uploadedByUserId, $meta, $file): Document {
            // A re-upload of the same document type supersedes the previous
            // one rather than deleting it: the audit trail keeps both.
            Document::query()
                ->where('organization_id', $organizationId)
                ->where('type', $type->value)
                ->whereIn('status', [
                    DocumentStatus::PENDING->value,
                    DocumentStatus::VERIFIED->value,
                    DocumentStatus::REJECTED->value,
                    DocumentStatus::EXPIRED->value,
                ])
                ->update(['status' => DocumentStatus::SUPERSEDED->value]);

            return Document::query()->create([
                'organization_id' => $organizationId,
                'type' => $type->value,
                'disk' => $disk,
                'storage_path' => $path,
                'original_filename' => $meta['original_filename'] ?? $file->getClientOriginalName(),
                'file_hash' => $hash,
                'mime_type' => $mime,
                'size_bytes' => $size,
                'status' => DocumentStatus::PENDING->value,
                'uploaded_by_user_id' => $uploadedByUserId,
                'expires_at' => $meta['expires_at'] ?? null,
            ]);
        });
    }

    /**
     * The member's own uploads, newest first.
     *
     * Added for `GET /organization/documents`: a controller may not query
     * Eloquent, and the ordering ("the newest upload of each type is the one
     * that counts") is a domain statement, not a presentation choice.
     *
     * @return list<Document>
     */
    public function forOrganization(int $organizationId): array
    {
        return Document::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    public function find(int $documentId): ?Document
    {
        /** @var Document|null */
        return Document::query()->find($documentId);
    }

    /**
     * Remove an upload the member changed its mind about.
     *
     * A VERIFIED document is refused: a compliance officer has already made a
     * decision citing it, and deleting the evidence would leave that decision
     * unsupported. The member's route to replacing it is a re-upload, which
     * SUPERSEDES rather than destroys (see store()).
     *
     * The bytes go after the row, and outside the transaction: a failed object
     * delete must not roll back the database, and an orphaned object is a
     * janitor's problem while an orphaned row is a broken dossier.
     *
     * @throws KycOperationException when the document has already been verified
     */
    public function delete(Document $document): void
    {
        if ($document->status === DocumentStatus::VERIFIED) {
            throw KycOperationException::documentAlreadyVerified((int) $document->id);
        }

        $disk = (string) $document->disk;
        $path = (string) $document->storage_path;

        DB::transaction(static function () use ($document): void {
            $document->delete();
        });

        Storage::disk($disk)->delete($path);
    }

    /**
     * Short-lived download URL. Callers must write an audit entry for every
     * call — every document view is auditable (docs §1.5).
     */
    public function temporaryUrl(Document $document, int $minutes = 5): string
    {
        $disk = Storage::disk((string) $document->disk);

        if (! method_exists($disk, 'temporaryUrl')) {
            // The local driver cannot sign URLs; return the raw path so local
            // development still works. Never reachable in production, where the
            // disk is S3.
            return $document->storage_path;
        }

        return $disk->temporaryUrl($document->storage_path, now()->addMinutes($minutes));
    }

    /**
     * The URL an API response may carry, or null when there is none.
     *
     * temporaryUrl() falls back to the raw storage path on a driver that
     * cannot sign — useful in a console script, catastrophic in a response
     * body, because Document::$hidden exists precisely to keep that path out
     * of the API. So this wrapper returns null unless it got back something
     * that is unambiguously a URL, and swallows the local driver's "does not
     * support temporary URLs" refusal.
     */
    public function downloadUrlFor(Document $document, int $minutes = 5): ?string
    {
        try {
            $url = $this->temporaryUrl($document, $minutes);
        } catch (Throwable) {
            return null;
        }

        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://') ? $url : null;
    }

    /** Verify the stored bytes still hash to what we recorded. */
    public function verifyIntegrity(Document $document): bool
    {
        $disk = Storage::disk((string) $document->disk);

        if (! $disk->exists($document->storage_path)) {
            return false;
        }

        return hash_equals($document->file_hash, hash('sha256', (string) $disk->get($document->storage_path)));
    }

    public function markVerified(Document $document, int $verifierUserId): Document
    {
        $this->assertTransition($document, DocumentStatus::VERIFIED);

        $document->status = DocumentStatus::VERIFIED;
        $document->verified_at = now();
        $document->verified_by_user_id = $verifierUserId;
        $document->rejection_reason = null;
        $document->save();

        return $document;
    }

    public function markRejected(Document $document, int $verifierUserId, string $reason): Document
    {
        if (trim($reason) === '') {
            throw new OperationNotPermittedException('document_rejection_requires_a_reason');
        }

        $this->assertTransition($document, DocumentStatus::REJECTED);

        $document->status = DocumentStatus::REJECTED;
        $document->verified_at = now();
        $document->verified_by_user_id = $verifierUserId;
        $document->rejection_reason = $reason;
        $document->save();

        return $document;
    }

    private function assertTransition(Document $document, DocumentStatus $target): void
    {
        if (! $document->status->canTransitionTo($target)) {
            throw new OperationNotPermittedException(
                'document_transition:'.$document->status->value.'->'.$target->value
            );
        }
    }

    private function assertAcceptable(string $mime, int $size): void
    {
        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidArgumentException("Unsupported document type: {$mime}");
        }

        if ($size <= 0 || $size > self::MAX_SIZE_BYTES) {
            throw new InvalidArgumentException('Document must be between 1 byte and 10 MB');
        }
    }

    /** Unguessable path; the organisation id prefix only aids operations. */
    private function buildPath(int $organizationId, DocumentType $type, UploadedFile $file): string
    {
        $extension = match ($file->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
            default => 'bin',
        };

        return sprintf(
            'kyc/%d/%s/%s.%s',
            $organizationId,
            strtolower($type->value),
            Str::uuid()->toString(),
            $extension,
        );
    }

    private function diskName(): string
    {
        return (string) config('filesystems.default', 'local');
    }
}
