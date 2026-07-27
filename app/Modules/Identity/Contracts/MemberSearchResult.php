<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

/**
 * One hit from `IdentityDirectory::search()` — the member-directory row another
 * module may show while picking an OTC or RFQ counterparty
 * (docs/05-api/02-endpoints.md §2.11, `GET /members/search`).
 *
 * The field list is the point. A directory search is reachable by every role in
 * every member, so it is the widest read surface in the platform, and it is
 * built as a hard allow-list rather than a filtered `OrganizationSnapshot`: the
 * national id, the legal id, their blind indexes, the mobile, the email, the
 * risk level and every balance simply have no field here to land in. A column
 * added to `organizations` tomorrow cannot leak through this object.
 *
 * `OrganizationSnapshot` is deliberately not reused — it carries
 * `registrationNo`, `riskLevel` and the `hasNationalId` / `hasLegalId` presence
 * flags, none of which a stranger has any business seeing.
 */
final readonly class MemberSearchResult
{
    public function __construct(
        public int $organizationId,
        public string $displayName,
        public string $city,
        public string $type,
        public string $status,
    ) {}

    /** @return array{organization_id:int, display_name:string, city:string, type:string, status:string} */
    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'display_name' => $this->displayName,
            'city' => $this->city,
            'type' => $this->type,
            'status' => $this->status,
        ];
    }
}
