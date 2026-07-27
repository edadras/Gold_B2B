<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Http\Requests;

use App\Modules\Kyc\Application\DocumentService;
use App\Modules\Kyc\Domain\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * `POST /organization/documents` — multipart upload.
 *
 * The limits repeat DocumentService::MAX_SIZE_BYTES and ALLOWED_MIME_TYPES on
 * purpose: the service raises InvalidArgumentException, which is a 500, and a
 * member sending a 12 MB photo deserves a field error. The service keeps its
 * own check because it is also called from console imports.
 *
 * `mimes:` inspects the real content type rather than trusting the filename or
 * the client's Content-Type header.
 */
final class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(DocumentType::values())],
            'file' => [
                'required',
                'file',
                'mimes:jpeg,jpg,png,pdf',
                'max:'.(int) (DocumentService::MAX_SIZE_BYTES / 1024),
            ],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ];
    }

    public function documentType(): DocumentType
    {
        return DocumentType::from((string) $this->validated('type'));
    }

    public function uploadedFile(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');

        return $file;
    }

    /** @return array<string, mixed> */
    public function meta(): array
    {
        return array_filter([
            'expires_at' => $this->validated('expires_at'),
        ], static fn (mixed $v): bool => $v !== null);
    }
}
