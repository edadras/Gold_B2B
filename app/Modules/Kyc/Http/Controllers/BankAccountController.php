<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Http\Controllers;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Kyc\Application\BankAccountService;
use App\Modules\Kyc\Http\Requests\StoreBankAccountRequest;
use App\Modules\Kyc\Http\Resources\BankAccountResource;
use App\Modules\Kyc\Infrastructure\Models\BankAccount;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/organization/bank-accounts` — docs/05-api/02-endpoints.md §2.2.
 *
 * Adding and removing an account carries the ✍️ marker: these are the rows
 * settlement pays money to, so changing them is re-authenticated with a
 * transaction signature (`transaction.sign`), not just a bearer token. The
 * signature check is mounted before `idempotency` on any route that has both,
 * so a refused code never burns a key.
 */
final class BankAccountController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly BankAccountService $accounts,
    ) {
        parent::__construct($authorization);
    }

    /**
     * AUTHORISATION — both legs. BANK_ACCOUNT_MANAGE has to be granted by the
     * caller's roles AND the accounts being listed have to belong to the
     * caller's organisation. The service scopes its query by the same id, but
     * a query scope is not an authorisation control — withoutGlobalScope() or
     * a raw statement bypasses it — so the tenancy leg is asserted here too.
     */
    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        $this->permit($request, Permission::BANK_ACCOUNT_MANAGE->value, $organizationId);

        return ApiResponse::collection(
            BankAccountResource::collection($this->accounts->forOrganization($organizationId)),
        );
    }

    /**
     * 👑 OWNER ✍️ — register an account.
     *
     * AUTHORISATION — both legs: the role grant, and the fact that the account
     * is created inside the caller's own organisation, whose id is the one
     * handed to permit() and the only one that reaches the write. The request
     * body has no organisation field, so there is no cross-tenant write path.
     */
    public function store(StoreBankAccountRequest $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        $this->permit($request, Permission::BANK_ACCOUNT_MANAGE->value, $organizationId);

        /** @var array{iban: string, bank_name: string, account_holder_name: string} $attributes */
        $attributes = $request->safe()->all();

        $account = $this->accounts->create($organizationId, $attributes);

        return ApiResponse::item(new BankAccountResource($account), 201);
    }

    /**
     * 👑 OWNER ✍️ — remove an account.
     *
     * AUTHORISATION — both legs, tenancy first. The row is loaded by id and
     * matched against the caller's organisation before anything else: another
     * member's account id answers 404, not 403, because 403 would confirm the
     * id exists and let a caller enumerate the platform's bank accounts. The
     * role leg is then checked against that same organisation. A tenant scope
     * on its own would not do either job.
     */
    public function destroy(Request $request, int $bankAccountId): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        $account = $this->accounts->find($bankAccountId);

        /** @var BankAccount $account */
        $account = $this->ownedOrNotFound(
            $account,
            $account === null ? null : (int) $account->organization_id,
            $organizationId,
        );

        $this->permit($request, Permission::BANK_ACCOUNT_MANAGE->value, (int) $account->organization_id);

        $this->accounts->delete($account);

        return ApiResponse::noContent();
    }
}
