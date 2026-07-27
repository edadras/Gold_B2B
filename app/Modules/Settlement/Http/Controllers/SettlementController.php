<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Http\Controllers;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Settlement\Application\GoldTransferService;
use App\Modules\Settlement\Application\PartialSettlementService;
use App\Modules\Settlement\Application\PaymentService;
use App\Modules\Settlement\Application\SettlementQueryService;
use App\Modules\Settlement\Http\Requests\CancelSettlementRequest;
use App\Modules\Settlement\Http\Requests\ConfirmPaymentRequest;
use App\Modules\Settlement\Http\Requests\DeclarePaymentRequest;
use App\Modules\Settlement\Http\Requests\SettlementIndexRequest;
use App\Modules\Settlement\Http\Resources\SettlementEventResource;
use App\Modules\Settlement\Http\Resources\SettlementResource;
use App\Modules\Settlement\Infrastructure\Models\SettlementModel;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Contracts\PayoutAccountDirectory;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Support\Cursor;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/settlements` — docs/05-api/02-endpoints.md §2.8.
 *
 * A settlement has four party columns rather than one owner, so tenancy is
 * "the caller is one of the parties" and not "organization_id matches". That
 * check lives in SettlementQueryService, in SQL, and every method here funnels
 * through it — a settlement between two strangers is a 404 exactly like one
 * that does not exist.
 */
final class SettlementController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly SettlementQueryService $query,
        private readonly PaymentService $payments,
        private readonly GoldTransferService $goldTransfers,
        private readonly PartialSettlementService $partials,
        private readonly PayoutAccountDirectory $payoutAccounts,
    ) {
        parent::__construct($authorization);
    }

    public function index(SettlementIndexRequest $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // Both legs: BALANCE_VIEW is the role grant for the settlement blotter,
        // and the query is scoped to settlements the caller is a party to. The
        // role check alone would let a VIEWER read a stranger's obligations;
        // the scope alone is bypassed by any raw query, so both are asserted.
        $this->permit($request, Permission::BALANCE_VIEW->value, $organizationId);

        $limit = Cursor::limit($request);

        $settlements = $this->query->forOrganization(
            $organizationId,
            $request->status(),
            Cursor::afterId($request),
            $limit,
        );

        return ApiResponse::collection(
            $this->present($settlements->all(), $organizationId),
            Cursor::links(
                $request,
                $settlements->map(static fn (SettlementModel $s): int => (int) $s->id)->all(),
                $limit,
            ),
        );
    }

    /** Only the settlements this member has to act on next. */
    public function pending(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::BALANCE_VIEW->value, $organizationId);

        $settlements = $this->query->pendingFor($organizationId);

        return ApiResponse::collection($this->present($settlements->all(), $organizationId));
    }

    public function show(Request $request, int $settlementId): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::BALANCE_VIEW->value, $organizationId);

        $settlement = $this->settlementOrNotFound($settlementId, $organizationId);

        return ApiResponse::item($this->resource($settlement, $organizationId));
    }

    public function events(Request $request, int $settlementId): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::BALANCE_VIEW->value, $organizationId);

        // Party membership is confirmed before the history is read, so the
        // timeline of somebody else's settlement is never assembled at all.
        $this->settlementOrNotFound($settlementId, $organizationId);

        return ApiResponse::collection(
            SettlementEventResource::collection($this->query->events($settlementId)),
        );
    }

    /**
     * The payer declares it has sent the money.
     *
     * PAYMENT_CONFIRM_SENT is the permission a TREASURER holds. The service
     * re-checks the state machine under a row lock, so a double declaration
     * loses there rather than here.
     */
    public function declarePayment(DeclarePaymentRequest $request, int $settlementId): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::PAYMENT_CONFIRM_SENT->value, $organizationId);

        $settlement = $this->settlementOrNotFound($settlementId, $organizationId);

        // Being a party is not enough: only the cash payer may declare a
        // payment. The gold deliverer on the same settlement must not.
        if ((int) $settlement->cash_payer_org_id !== $organizationId) {
            return ApiResponse::error(
                'SETTLEMENT_NOT_YOUR_TURN',
                'اعلام پرداخت فقط توسط پرداخت‌کننده انجام می‌شود.',
                422,
                ['required_role' => 'CASH_PAYER'],
            );
        }

        $paidAt = $request->validated('paid_at');

        $this->payments->declarePayment(
            settlementId: $settlementId,
            actorUserId: $this->userId($request),
            paymentReference: (string) $request->validated('payment_reference'),
            amountRial: $request->validated('amount_rial'),
            paidAt: $paidAt === null ? null : CarbonImmutable::parse((string) $paidAt),
            bankTransactionId: $request->validated('bank_transaction_id'),
        );

        return ApiResponse::item(
            $this->resource($this->reload($settlementId, $organizationId), $organizationId),
        );
    }

    /** The receiver confirms the money arrived. ✍️ signed on the route. */
    public function confirmPayment(ConfirmPaymentRequest $request, int $settlementId): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::PAYMENT_CONFIRM_RECEIVED->value, $organizationId);

        $settlement = $this->settlementOrNotFound($settlementId, $organizationId);

        if ((int) $settlement->cash_receiver_org_id !== $organizationId) {
            return ApiResponse::error(
                'SETTLEMENT_NOT_YOUR_TURN',
                'تأیید دریافت فقط توسط دریافت‌کننده انجام می‌شود.',
                422,
                ['required_role' => 'CASH_RECEIVER'],
            );
        }

        $this->payments->confirmPayment(
            settlementId: $settlementId,
            actorUserId: $this->userId($request),
            paymentId: $request->validated('payment_id'),
        );

        return ApiResponse::item(
            $this->resource($this->reload($settlementId, $organizationId), $organizationId),
        );
    }

    /** The gold deliverer hands the metal over. ✍️ signed on the route. */
    public function confirmDelivery(Request $request, int $settlementId): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::DELIVERY_CONFIRM->value, $organizationId);

        $settlement = $this->settlementOrNotFound($settlementId, $organizationId);

        if ((int) $settlement->gold_deliverer_org_id !== $organizationId) {
            return ApiResponse::error(
                'SETTLEMENT_NOT_YOUR_TURN',
                'تأیید تحویل طلا فقط توسط تحویل‌دهنده انجام می‌شود.',
                422,
                ['required_role' => 'GOLD_DELIVERER'],
            );
        }

        $this->goldTransfers->transfer($settlementId, $this->userId($request));

        return ApiResponse::item(
            $this->resource($this->reload($settlementId, $organizationId), $organizationId),
        );
    }

    public function cancel(CancelSettlementRequest $request, int $settlementId): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::SETTLEMENT_CONFIRM->value, $organizationId);

        $this->settlementOrNotFound($settlementId, $organizationId);

        $this->partials->abandon(
            $settlementId,
            (string) $request->validated('reason'),
            $this->userId($request),
        );

        return ApiResponse::item(
            $this->resource($this->reload($settlementId, $organizationId), $organizationId),
        );
    }

    /**
     * @param  list<SettlementModel>  $settlements
     * @return list<SettlementResource>
     */
    private function present(array $settlements, int $organizationId): array
    {
        return array_map(
            fn (SettlementModel $s): SettlementResource => $this->resource($s, $organizationId, withPayment: false),
            $settlements,
        );
    }

    /**
     * The destination account is only looked up when the viewer is the cash
     * payer: a lookup for anybody else would decrypt an IBAN nobody is going to
     * be shown.
     */
    private function resource(
        SettlementModel $settlement,
        int $organizationId,
        bool $withPayment = true,
    ): SettlementResource {
        $isPayer = (int) $settlement->cash_payer_org_id === $organizationId;

        return new SettlementResource(
            $settlement,
            $organizationId,
            $isPayer ? $this->payoutAccounts->primaryFor((int) $settlement->cash_receiver_org_id) : null,
            $withPayment ? $this->query->latestPayment((int) $settlement->id) : null,
        );
    }

    private function settlementOrNotFound(int $settlementId, int $organizationId): SettlementModel
    {
        return $this->query->find($settlementId, $organizationId) ?? throw $this->notFound();
    }

    private function reload(int $settlementId, int $organizationId): SettlementModel
    {
        return $this->settlementOrNotFound($settlementId, $organizationId);
    }
}
