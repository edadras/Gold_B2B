<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Http\Resources;

use App\Modules\Identity\Contracts\MemberSearchResult;
use App\Modules\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * One hit from `GET /members/search` (docs §2.11).
 *
 * The payload is `MemberSearchResult::toArray()` verbatim — five fields, and no
 * `_display` companions. That is not laziness: the DTO is the allow-list, and a
 * resource that assembled its own field list would be a second place for a
 * national id, a mobile or a balance to creep in. There is nothing here to
 * format anyway; every value is already a short string.
 *
 * @mixin MemberSearchResult
 */
final class MemberSearchResultResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var MemberSearchResult $member */
        $member = $this->resource;

        return $member->toArray();
    }
}
