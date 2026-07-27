<?php

declare(strict_types=1);

namespace App\Modules\Trading\Http\Controllers;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Support\Cursor;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Trading\Application\CancelOrderService;
use App\Modules\Trading\Application\Commands\PlaceOrderCommand;
use App\Modules\Trading\Application\InstrumentRepository;
use App\Modules\Trading\Application\PlaceOrderService;
use App\Modules\Trading\Application\TradingQueryService;
use App\Modules\Trading\Http\Requests\CancelAllOrdersRequest;
use App\Modules\Trading\Http\Requests\OrderIndexRequest;
use App\Modules\Trading\Http\Requests\PlaceOrderRequest;
use App\Modules\Trading\Http\Resources\OrderResource;
use App\Modules\Trading\Infrastructure\Models\Order;
use App\Modules\Trading\Infrastructure\Models\OrderFill;
use App\Modules\Trading\Infrastructure\Models\Trade;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/orders` — docs/05-api/02-endpoints.md §2.5.
 *
 * The mutating endpoints all carry the `idempotency` middleware: a dropped
 * response on a mobile network must never be able to place a second order.
 */
final class OrderController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly PlaceOrderService $placeOrders,
        private readonly CancelOrderService $cancelOrders,
        private readonly TradingQueryService $query,
        private readonly InstrumentRepository $instruments,
    ) {
        parent::__construct($authorization);
    }

    public function index(OrderIndexRequest $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // Both legs. ORDER_BOOK_VIEW is the read grant, and $organizationId is
        // the caller's own, so the permission check also asserts tenancy. The
        // query below is independently scoped to the same id: a role check
        // alone would let a TRADER page through a stranger's blotter, and a
        // scope alone is bypassed by any raw query or withoutGlobalScope().
        $this->permit($request, Permission::ORDER_BOOK_VIEW->value, $organizationId);

        $limit = Cursor::limit($request);
        $instrumentCode = $request->instrumentCode();

        $orders = $this->query->orders(
            organizationId: $organizationId,
            statuses: $request->statuses(),
            instrumentId: $instrumentCode === null
                ? null
                : $this->instruments->findByCode($instrumentCode)?->id,
            side: $request->side(),
            beforeId: Cursor::afterId($request),
            limit: $limit,
        );

        $codes = $this->instrumentCodes($orders->pluck('instrument_id')->all());

        return ApiResponse::collection(
            $orders->map(fn (Order $order): OrderResource => new OrderResource(
                $order,
                $codes[(int) $order->instrument_id] ?? null,
            ))->all(),
            Cursor::links($request, $orders->map(static fn (Order $o): int => (int) $o->id)->all(), $limit),
        );
    }

    public function show(Request $request, int $orderId): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::ORDER_BOOK_VIEW->value, $organizationId);

        $order = $this->query->order($orderId, $organizationId);

        // An order belonging to another member is reported exactly like one
        // that does not exist. A 403 here would confirm the id is live and let
        // a caller enumerate the platform's order sequence.
        if ($order === null) {
            throw $this->notFound();
        }

        $fills = $this->query->fills($orderId);

        return ApiResponse::item(new OrderResource(
            $order,
            $this->instruments->find((int) $order->instrument_id)?->code,
            $fills,
            $this->tradeCodes($fills->pluck('trade_id')->all()),
        ));
    }

    public function fills(Request $request, int $orderId): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::ORDER_BOOK_VIEW->value, $organizationId);

        $order = $this->query->order($orderId, $organizationId);

        if ($order === null) {
            throw $this->notFound();
        }

        $fills = $this->query->fills($orderId);
        $codes = $this->tradeCodes($fills->pluck('trade_id')->all());

        return ApiResponse::collection(
            $fills->map(static fn (OrderFill $fill): array => [
                'trade_code' => $codes[(int) $fill->trade_id] ?? null,
                'role' => (string) $fill->role,
                'side' => $fill->side->value,
                'quantity_mg' => (int) $fill->quantity_mg,
                'price_rial' => (int) $fill->price_rial,
                'gross_amount_rial' => (int) $fill->gross_amount_rial,
                'fee_rial' => (int) $fill->fee_rial,
            ])->all(),
        );
    }

    public function store(PlaceOrderRequest $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // ORDER_CREATE is the write grant. PermissionChecker additionally
        // refuses it when the organisation is not ACTIVE, which is how a
        // SUSPENDED member is stopped from opening new exposure while still
        // being allowed to settle what it owes.
        $this->permit($request, Permission::ORDER_CREATE->value, $organizationId);

        $expiresAt = $request->validated('expires_at');

        $result = $this->placeOrders->place(new PlaceOrderCommand(
            organizationId: $organizationId,
            userId: $this->userId($request),
            representativeId: $request->validated('representative_id'),
            instrumentCode: (string) $request->validated('instrument'),
            side: $request->side(),
            type: $request->type(),
            timeInForce: $request->timeInForce(),
            quantity: FineWeight::fromMilligrams((int) $request->validated('quantity_mg')),
            price: $request->validated('price_rial') === null
                ? null
                : PricePerFineGram::fromRial((int) $request->validated('price_rial')),
            maxSlippageBps: $request->validated('max_slippage_bps'),
            expiresAt: $expiresAt === null ? null : CarbonImmutable::parse((string) $expiresAt),
        ));

        $order = $result->order;
        $fills = $this->query->fills((int) $order->id);

        return ApiResponse::item(new OrderResource(
            $order,
            (string) $request->validated('instrument'),
            $fills,
            $this->tradeCodes($fills->pluck('trade_id')->all()),
        ), 201);
    }

    public function cancel(Request $request, int $orderId): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // ORDER_CANCEL_OWN is the baseline; a member with ORDER_CANCEL_ANY may
        // cancel a colleague's order. Either way the order must belong to the
        // caller's organisation — CancelOrderService re-checks that under the
        // row lock and raises a not-found of its own if it does not.
        $permission = $this->may($request, Permission::ORDER_CANCEL_ANY->value, $organizationId)
            ? Permission::ORDER_CANCEL_ANY
            : Permission::ORDER_CANCEL_OWN;

        $this->permit($request, $permission->value, $organizationId);

        $order = $this->cancelOrders->cancel(
            orderId: $orderId,
            organizationId: $organizationId,
            userId: $this->userId($request),
        );

        return ApiResponse::item(new OrderResource(
            $order,
            $this->instruments->find((int) $order->instrument_id)?->code,
        ));
    }

    public function cancelAll(CancelAllOrdersRequest $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::ORDER_CANCEL_OWN->value, $organizationId);

        $instrumentCode = $request->validated('instrument');

        $ids = $this->query->openOrderIds(
            $organizationId,
            $instrumentCode === null ? null : $this->instruments->findByCode((string) $instrumentCode)?->id,
        );

        $cancelled = [];
        $failed = [];

        // Ascending id order (AGENT_BRIEF rule 4) — each cancel takes its own
        // row lock, and a fixed order stops two concurrent cancel-alls from
        // deadlocking against each other.
        foreach ($ids as $id) {
            try {
                $this->cancelOrders->cancel($id, $organizationId, $this->userId($request), 'cancel-all');
                $cancelled[] = $id;
            } catch (\App\Modules\Shared\Exceptions\DomainException $e) {
                // An order that filled between the listing and the cancel is
                // not an error for the batch: report it and keep going.
                $failed[] = ['order_id' => $id, 'code' => $e->errorCode()];
            }
        }

        return ApiResponse::item([
            'cancelled_count' => count($cancelled),
            'cancelled_order_ids' => $cancelled,
            'failed' => $failed,
        ]);
    }

    /**
     * @param  list<int|string>  $instrumentIds
     * @return array<int, string>
     */
    private function instrumentCodes(array $instrumentIds): array
    {
        $codes = [];

        foreach (array_unique(array_map('intval', $instrumentIds)) as $id) {
            $code = $this->instruments->find($id)?->code;

            if ($code !== null) {
                $codes[$id] = (string) $code;
            }
        }

        return $codes;
    }

    /**
     * @param  list<int|string>  $tradeIds
     * @return array<int, string>
     */
    private function tradeCodes(array $tradeIds): array
    {
        if ($tradeIds === []) {
            return [];
        }

        return Trade::query()
            ->whereIn('id', array_map('intval', $tradeIds))
            ->pluck('trade_code', 'id')
            ->map(static fn (mixed $code): string => (string) $code)
            ->all();
    }
}
