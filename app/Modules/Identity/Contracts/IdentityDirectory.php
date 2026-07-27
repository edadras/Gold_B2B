<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

/**
 * The only supported way for another module to ask Identity a question.
 *
 * Trading, Settlement, Custody and Risk bind against this interface; they never
 * import an Identity Eloquent model (AGENT_BRIEF rule 7).
 */
interface IdentityDirectory
{
    public function findOrganization(int $organizationId): ?OrganizationSnapshot;

    public function findUser(int $userId): ?UserSnapshot;

    /**
     * Member directory search, for the counterparty picker of §2.11
     * (`GET /members/search`).
     *
     * Returns `MemberSearchResult`, not `OrganizationSnapshot`: the caller is a
     * stranger to every row it gets back, so the payload is a hard allow-list
     * of the five fields a counterparty picker needs. Platform organisations
     * are never included — they are not tradeable counterparties and their
     * presence in a member-facing list is noise at best.
     *
     * Excluding the *caller's own* organisation is not done here, because this
     * interface has no notion of who is calling; the caller filters its own id
     * out. Requesting `$limit` rows therefore may yield one fewer.
     *
     * @param  int  $limit  maximum rows to return
     * @return list<MemberSearchResult>
     */
    public function search(string $query, int $limit): array;

    /**
     * Identity's verdicts on the member's registered identifiers, for the
     * compliance review screen.
     *
     * Lives here rather than on the snapshot because it costs extra queries
     * and only the KYC review path wants it; `findOrganization` stays cheap
     * enough for hot paths like order placement.
     */
    public function organizationIdentityChecks(int $organizationId): ?OrganizationIdentityChecks;

    /** True only when the member is ACTIVE and may open new exposure. */
    public function organizationCanTrade(int $organizationId): bool;

    /** True while the member may still settle existing obligations. */
    public function organizationCanSettle(int $organizationId): bool;

    /**
     * Full three-layer check: user active, tenancy matches, permission granted,
     * organisation status permits the action.
     */
    public function userMayActOn(int $userId, string $permission, int $organizationId): bool;

    /**
     * Whether the user currently holds a valid representative authority of the
     * given type — a TRADER without one may not trade (docs §1.7).
     */
    public function userHasRepresentativeAuthority(int $userId, string $authorityType): bool;
}
