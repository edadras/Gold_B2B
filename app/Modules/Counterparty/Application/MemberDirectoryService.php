<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Application;

use App\Modules\Identity\Contracts\IdentityDirectory;
use App\Modules\Identity\Contracts\MemberSearchResult;

/**
 * The counterparty picker behind `GET /members/search` (docs §2.11).
 *
 * Thin on purpose: Identity owns the member directory and answers through
 * `IdentityDirectory::search()`, which already restricts the payload to the
 * five fields a picker needs and drops platform organisations. What this
 * service adds is the one thing the directory cannot know — who is asking — so
 * that the caller never finds itself in its own counterparty list.
 *
 * It exists at all so the controller calls one application service like every
 * other endpoint, instead of reaching into another module's contract and then
 * filtering the result in the HTTP layer.
 */
final class MemberDirectoryService
{
    public function __construct(private readonly IdentityDirectory $directory) {}

    /**
     * @param  int  $callerOrganizationId  excluded from the results
     * @return list<MemberSearchResult>
     */
    public function search(string $query, int $callerOrganizationId, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        // One row of slack, so removing the caller's own hit does not silently
        // return a short page.
        $found = $this->directory->search($query, $limit + 1);

        $filtered = array_values(array_filter(
            $found,
            static fn (MemberSearchResult $m): bool => $m->organizationId !== $callerOrganizationId,
        ));

        return array_slice($filtered, 0, $limit);
    }
}
