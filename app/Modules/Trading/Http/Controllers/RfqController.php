<?php

declare(strict_types=1);

namespace App\Modules\Trading\Http\Controllers;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Trading\Application\Commands\CreateRfqCommand;
use App\Modules\Trading\Application\InstrumentRepository;
use App\Modules\Trading\Application\RfqService;
use App\Modules\Trading\Application\TradingQueryService;
use App\Modules\Trading\Domain\DeliveryType;
use App\Modules\Trading\Domain\Side;
use App\Modules\Trading\Http\Requests\AcceptRfqQuoteRequest;
use App\Modules\Trading\Http\Requests\CreateRfqRequest;
use App\Modules\Trading\Http\Requests\QuoteRfqRequest;
use App\Modules\Trading\Http\Resources\RfqQuoteResource;
use App\Modules\Trading\Http\Resources\RfqResource;
use App\Modules\Trading\Http\Resources\TradeResource;
use App\Modules\Trading\Infrastructure\Models\Rfq;
use App\Modules\Trading\Infrastructure\Models\RfqQuote;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/rfqs` and `/rfq-quotes` — docs/05-api/02-endpoints.md §2.7.
 *
 * Visibility is the interesting part here and it is enforced in
 * TradingQueryService, not in a resource: an RFQ the caller was not invited to
 * is 404, and a competitor's quote on an RFQ the caller merely answered is
 * never returned at all.
 */
final class RfqController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly RfqService $rfqs,
        private readonly TradingQueryService $query,
        private readonly InstrumentRepository $instruments,
    ) {
        parent::__construct($authorization);
    }

    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // Both legs: RFQ_CREATE is the role grant for a member's own requests,
        // and the query is scoped to organization_id.
        $this->permit($request, Permission::RFQ_CREATE->value, $organizationId);

        $rfqs = $this->query->myRfqs($organizationId);

        return ApiResponse::collection($this->present($rfqs->all(), $organizationId));
    }

    /** RFQs addressed to this member — the "answer a request" screen. */
    public function inbox(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::RFQ_RESPOND->value, $organizationId);

        $rfqs = $this->query->rfqInbox($organizationId);

        return ApiResponse::collection($this->present($rfqs->all(), $organizationId));
    }

    public function show(Request $request, int $rfqId): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::ORDER_BOOK_VIEW->value, $organizationId);

        $rfq = $this->rfqOrNotFound($rfqId, $organizationId);

        return ApiResponse::item(new RfqResource(
            $rfq,
            $organizationId,
            $this->instruments->find((int) $rfq->instrument_id)?->code,
        ));
    }

    public function store(CreateRfqRequest $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::RFQ_CREATE->value, $organizationId);

        $deliveryType = $request->validated('delivery_type');

        $rfq = $this->rfqs->create(new CreateRfqCommand(
            organizationId: $organizationId,
            userId: $this->userId($request),
            instrumentCode: (string) $request->validated('instrument'),
            side: Side::from((string) $request->validated('side')),
            quantity: FineWeight::fromMilligrams((int) $request->validated('quantity_mg')),
            expiresAt: CarbonImmutable::now()->addMinutes((int) $request->validated('expires_in_minutes')),
            visibility: $request->visibility(),
            recipientOrgIds: array_map('intval', (array) $request->validated('recipient_organization_ids', [])),
            allowPartial: (bool) $request->validated('allow_partial', true),
            minPurityX10: $request->validated('min_purity_x10'),
            deliveryType: $deliveryType === null ? null : DeliveryType::from((string) $deliveryType),
        ));

        return ApiResponse::item(new RfqResource(
            $rfq,
            $organizationId,
            (string) $request->validated('instrument'),
        ), 201);
    }

    public function cancel(Request $request, int $rfqId): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::RFQ_CREATE->value, $organizationId);

        $rfq = $this->rfqOrNotFound($rfqId, $organizationId);

        // Only the requester may cancel; an invited member seeing the RFQ must
        // not be able to withdraw somebody else's request.
        if ((int) $rfq->organization_id !== $organizationId) {
            throw $this->notFound();
        }

        $rfq->status = \App\Modules\Trading\Domain\RfqStatus::CANCELLED;
        $rfq->closed_at = now();
        $rfq->save();

        return ApiResponse::item(new RfqResource($rfq, $organizationId));
    }

    public function quotes(Request $request, int $rfqId): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::ORDER_BOOK_VIEW->value, $organizationId);

        $rfq = $this->rfqOrNotFound($rfqId, $organizationId);

        $quotes = $this->query->rfqQuotes($rfq, $organizationId);

        return ApiResponse::collection(
            $quotes->map(static fn (RfqQuote $q): RfqQuoteResource => new RfqQuoteResource($q, $organizationId))->all(),
        );
    }

    public function quote(QuoteRfqRequest $request, int $rfqId): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::RFQ_RESPOND->value, $organizationId);

        $this->rfqOrNotFound($rfqId, $organizationId);

        $quote = $this->rfqs->quote(
            rfqId: $rfqId,
            quoterOrganizationId: $organizationId,
            userId: $this->userId($request),
            quantity: FineWeight::fromMilligrams((int) $request->validated('quantity_mg')),
            price: PricePerFineGram::fromRial((int) $request->validated('price_per_gram_rial')),
            validUntil: CarbonImmutable::now()->addMinutes((int) $request->validated('valid_for_minutes')),
        );

        return ApiResponse::item(new RfqQuoteResource($quote, $organizationId), 201);
    }

    public function acceptQuote(AcceptRfqQuoteRequest $request, int $quoteId): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::RFQ_CREATE->value, $organizationId);

        $quote = $this->query->rfqQuote($quoteId, $organizationId) ?? throw $this->notFound();

        $quantity = $request->validated('quantity_mg');

        $trade = $this->rfqs->accept(
            rfqId: (int) $quote->rfq_id,
            quoteId: $quoteId,
            requesterOrganizationId: $organizationId,
            acceptQuantity: $quantity === null ? null : FineWeight::fromMilligrams((int) $quantity),
        );

        return ApiResponse::item(new TradeResource(
            $trade,
            $organizationId,
            $this->instruments->find((int) $trade->instrument_id)?->code,
        ), 201);
    }

    public function withdrawQuote(Request $request, int $quoteId): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::RFQ_RESPOND->value, $organizationId);

        $quote = $this->query->rfqQuote($quoteId, $organizationId) ?? throw $this->notFound();

        if ((int) $quote->quoter_organization_id !== $organizationId) {
            throw $this->notFound();
        }

        $withdrawn = $this->rfqs->withdrawQuote($quoteId, $organizationId);

        return ApiResponse::item(new RfqQuoteResource($withdrawn, $organizationId));
    }

    /**
     * @param  list<Rfq>  $rfqs
     * @return list<RfqResource>
     */
    private function present(array $rfqs, int $organizationId): array
    {
        return array_map(
            fn (Rfq $rfq): RfqResource => new RfqResource(
                $rfq,
                $organizationId,
                $this->instruments->find((int) $rfq->instrument_id)?->code,
            ),
            $rfqs,
        );
    }

    private function rfqOrNotFound(int $rfqId, int $organizationId): Rfq
    {
        return $this->query->rfq($rfqId, $organizationId) ?? throw $this->notFound();
    }
}
