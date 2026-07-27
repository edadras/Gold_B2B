<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Http\Resources;

use App\Modules\Dispute\Domain\EvidenceType;
use App\Modules\Dispute\Infrastructure\Models\DisputeEvidenceModel;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * One filed piece of evidence — `POST /disputes/{id}/evidences`.
 *
 * `is_system_generated` is surfaced so the UI can mark a platform-attached
 * record differently from a member upload; the two carry very different weight
 * and a list that rendered them identically would be misleading.
 *
 * @mixin DisputeEvidenceModel
 */
final class DisputeEvidenceResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DisputeEvidenceModel $evidence */
        $evidence = $this->resource;

        $type = EvidenceType::tryFrom((string) $evidence->evidence_type);

        return [
            'id' => (int) $evidence->id,
            'dispute_id' => (int) $evidence->dispute_id,
            'evidence_type' => (string) $evidence->evidence_type,
            'description' => (string) $evidence->description,
            'document_id' => $evidence->document_id === null ? null : (int) $evidence->document_id,
            'file_hash' => $evidence->file_hash,
            'submitted_by_org_id' => (int) $evidence->submitted_by_org_id,
            'is_system_generated' => $type?->isSystemGenerated() ?? false,
            'submitted_at' => Display::iso($evidence->submitted_at),
        ] + $this->display($request, [
            'evidence_type_display' => $type?->label(),
            'submitted_at_jalali' => Display::jalali($evidence->submitted_at),
        ]);
    }
}
