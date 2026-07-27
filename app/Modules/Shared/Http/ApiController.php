<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http;

use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Exceptions\ForbiddenException;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Base for every module's API controllers.
 *
 * Controllers stay thin — validate in a FormRequest, call one application
 * service, return a Resource — so the only logic that belongs here is the two
 * things every endpoint needs and no endpoint should re-invent: who is calling,
 * and whether they may.
 *
 * AUTHORISATION IS TWO-LEGGED, EVERYWHERE. `permit()` asks Identity's
 * AuthorizationGateway, which checks BOTH that the caller's roles grant the
 * permission AND that the resource's organisation is the caller's own. Neither
 * leg is sufficient alone:
 *
 *   · a role check alone would let a TRADER cancel another member's order;
 *   · a tenant scope alone would let a VIEWER place one — and an Eloquent
 *     global scope is bypassed by withoutGlobalScope() or a raw query, so
 *     tenancy is re-asserted explicitly here rather than trusted to the model.
 *
 * The second rule this class encodes is *how* a cross-tenant miss is reported:
 * `findForOrganizationOrFail()` raises 404, never 403. Answering 403 would
 * confirm that the id exists, which is a membership oracle over the whole
 * platform's order and settlement ids.
 */
abstract class ApiController
{
    public function __construct(protected readonly AuthorizationGateway $authorization) {}

    /** The authenticated user's id. */
    protected function userId(Request $request): int
    {
        $user = $request->user();

        if ($user === null) {
            throw new RuntimeException('ApiController used on a route without auth:sanctum');
        }

        return (int) $user->getAuthIdentifier();
    }

    /** The organisation every query in a member-facing endpoint must be scoped to. */
    protected function organizationId(Request $request): int
    {
        $user = $request->user();

        if ($user === null) {
            throw new RuntimeException('ApiController used on a route without auth:sanctum');
        }

        return (int) $user->getAttribute('organization_id');
    }

    /**
     * Leg 1 + leg 2. Pass the resource's owning organisation whenever the
     * resource was loaded by id; omit it only when the action creates something
     * inside the caller's own organisation.
     *
     * @throws ForbiddenException
     */
    protected function permit(Request $request, string $permission, ?int $resourceOrganizationId = null): void
    {
        $this->authorization->authorize($this->userId($request), $permission, $resourceOrganizationId);
    }

    protected function may(Request $request, string $permission, ?int $resourceOrganizationId = null): bool
    {
        return $this->authorization->allows($this->userId($request), $permission, $resourceOrganizationId);
    }

    /**
     * A resource that is missing, or belongs to somebody else, is 404 either way.
     *
     * @template T
     *
     * @param  T|null  $resource
     * @param  int|null  $ownerOrganizationId  the resource's organisation, null when it has none
     * @return T
     */
    protected function ownedOrNotFound(mixed $resource, ?int $ownerOrganizationId, int $callerOrganizationId): mixed
    {
        if ($resource === null || $ownerOrganizationId !== $callerOrganizationId) {
            throw new NotFoundHttpException('RESOURCE_NOT_FOUND');
        }

        return $resource;
    }

    /**
     * As above, but for a resource with two sides (a trade, a settlement, an
     * OTC offer): the caller must be one of them.
     *
     * @template T
     *
     * @param  T|null  $resource
     * @param  list<int>  $partyOrganizationIds
     * @return T
     */
    protected function partyOrNotFound(mixed $resource, array $partyOrganizationIds, int $callerOrganizationId): mixed
    {
        if ($resource === null || ! in_array($callerOrganizationId, $partyOrganizationIds, true)) {
            throw new NotFoundHttpException('RESOURCE_NOT_FOUND');
        }

        return $resource;
    }

    protected function notFound(): NotFoundHttpException
    {
        return new NotFoundHttpException('RESOURCE_NOT_FOUND');
    }
}
