<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Http\Requests;

use App\Modules\Dispute\Domain\EvidenceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /disputes/{id}/evidences — §2.12 «افزودن مدرک».
 *
 * SYSTEM_LOG is excluded at validation, not merely at the service. A
 * member-uploaded "system log" would carry the platform's authority without
 * being from it, which is exactly the confusion an UNAUTHORIZED_TRADE or
 * SYSTEM_ERROR claim turns on. EvidenceService refuses it too; refusing it here
 * as well means the client gets a field error naming the allowed types instead
 * of a generic business-rule failure.
 *
 * `file_hash` is a SHA-256 hex digest of the uploaded document. The file itself
 * is uploaded elsewhere; what is filed here is the promise that the bytes have
 * not changed since.
 */
final class SubmitEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $memberSubmittable = array_values(array_filter(
            array_map(static fn (EvidenceType $t): string => $t->value, EvidenceType::cases()),
            static fn (string $type): bool => ! EvidenceType::from($type)->isSystemGenerated(),
        ));

        return [
            'evidence_type' => ['required', Rule::in($memberSubmittable)],
            'description' => ['required', 'string', 'min:3', 'max:2000'],
            'document_id' => ['nullable', 'integer', 'min:1'],
            'file_hash' => ['nullable', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
        ];
    }

    public function evidenceType(): EvidenceType
    {
        return EvidenceType::from((string) $this->validated('evidence_type'));
    }

    public function documentId(): ?int
    {
        $id = $this->validated('document_id');

        return $id === null ? null : (int) $id;
    }

    public function fileHash(): ?string
    {
        $hash = $this->validated('file_hash');

        return is_string($hash) && $hash !== '' ? $hash : null;
    }
}
