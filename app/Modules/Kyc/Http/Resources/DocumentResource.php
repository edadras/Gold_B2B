<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Http\Resources;

use App\Modules\Kyc\Domain\DocumentStatus;
use App\Modules\Kyc\Infrastructure\Models\Document;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * An uploaded KYC document.
 *
 * SECURITY — the two fields this resource must never emit:
 *
 *   · `storage_path`. Document::$hidden hides it from a naive toArray(), but
 *     this class builds its payload field by field precisely so that hiding is
 *     not the only thing standing between the path and the client. The path is
 *     an unguessable UUID; publishing it turns "unguessable" into "known" and
 *     hands anyone with the disk's URL prefix a direct object reference.
 *   · the bytes or their location on any other disk.
 *
 * `download_url` is the sanctioned way to read the file: a short-lived signed
 * URL minted per request by DocumentService, only ever for the caller's own
 * documents, and null on a disk that cannot sign one.
 *
 * @mixin Document
 */
final class DocumentResource extends ApiResource
{
    public function __construct(mixed $resource, private readonly ?string $downloadUrl = null)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Document $document */
        $document = $this->resource;

        return [
            'id' => (int) $document->id,
            'organization_id' => (int) $document->organization_id,
            'type' => $document->type->value,
            'type_label' => $document->type->label(),
            'status' => $document->status->value,
            'original_filename' => $document->original_filename,
            'mime_type' => (string) $document->mime_type,
            'size_bytes' => (int) $document->size_bytes,
            // The SHA-256 of the bytes is the member's own tamper receipt; it
            // reveals nothing about where the file lives.
            'file_hash' => (string) $document->file_hash,
            'rejection_reason' => $document->rejection_reason,
            // Mirrors DocumentService::delete(): only an unverified upload may go.
            'is_deletable' => $document->status !== DocumentStatus::VERIFIED,
            'download_url' => $this->downloadUrl,
            'verified_at' => Display::iso($document->verified_at),
            'expires_at' => $document->expires_at?->toDateString(),
            'uploaded_at' => Display::iso($document->created_at),
        ] + $this->display($request, [
            'verified_at_jalali' => Display::jalali($document->verified_at),
            'expires_at_jalali' => Display::jalali($document->expires_at, withTime: false),
            'uploaded_at_jalali' => Display::jalali($document->created_at),
        ]);
    }
}
