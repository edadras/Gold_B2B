<?php

declare(strict_types=1);

namespace App\Modules\Trading\Http\Controllers;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Trading\Application\Commands\CreateOtcOfferCommand;
use App\Modules\Trading\Application\InstrumentRepository;
use App\Modules\Trading\Application\OtcService;
use App\Modules\Trading\Application\TradingQueryService;
use App\Modules\Trading\Domain\DeliveryType;
use App\Modules\Trading\Domain\Side;
use App\Modules\Trading\Http\Requests\CounterOtcOfferRequest;
use App\Modules\Trading\Http\Requests\CreateOtcOfferRequest;
use App\Modules\Trading\Http\Requests\RejectOtcOfferRequest;
use App\Modules\Trading\Http\Resources\OtcOfferResource;
use App\Modules\Trading\Http\Resources\TradeResource;
use App\Modules\Trading\Infrastructure\Models\OtcOffer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `/otc-offers` — docs/05-api/02-endpoints.md §2.6. */
final class OtcOfferController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly OtcService $otc,
        private readonly TradingQueryService $query,
        private readonly InstrumentRepository $instruments,
    ) {
        parent::__construct($authorization);
    }

    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // Both legs: OTC_TRADE is the role grant, and the query only ever
        // returns offers where the caller is one of the two named parties.
        $this->permit($request, Permission::OTC_TRADE->value, $organizationId);

        $direction = $request->query('direction');
        $offers = $this->query->otcOffers($organizationId, is_string($direction) ? $direction : null);

        return ApiResponse::collection(
            $offers->map(fn (OtcOffer $offer): OtcOfferResource => new OtcOfferResource(
                $offer,
                $organizationId,
                $this->instruments->find((int) $offer->instrument_id)?->code,
            ))->all(),
        );
    }

    public function show(Request $request, int $offerId): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::OTC_TRADE->value, $organizationId);

        $offer = $this->offerOrNotFound($offerId, $organizationId);

        return ApiResponse::item(new OtcOfferResource(
            $offer,
            $organizationId,
            $this->instruments->find((int) $offer->instrument_id)?->code,
        ));
    }

    public function store(CreateOtcOfferRequest $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::OTC_TRADE->value, $organizationId);

        $deliveryType = $request->validated('delivery_type');

        $offer = $this->otc->createOffer(new CreateOtcOfferCommand(
            organizationId: $organizationId,
            counterpartyOrganizationId: (int) $request->validated('counterparty_organization_id'),
            userId: $this->userId($request),
            instrumentCode: (string) $request->validated('instrument'),
            side: Side::from((string) $request->validated('side')),
            quantity: FineWeight::fromMilligrams((int) $request->validated('quantity_mg')),
            price: PricePerFineGram::fromRial((int) $request->validated('price_rial')),
            expiresAt: CarbonImmutable::now()->addMinutes((int) $request->validated('expires_in_minutes')),
            minPurityX10: $request->validated('min_purity_x10'),
            deliveryType: $deliveryType === null ? null : DeliveryType::from((string) $deliveryType),
        ));

        return ApiResponse::item(new OtcOfferResource(
            $offer,
            $organizationId,
            (string) $request->validated('instrument'),
        ), 201);
    }

    public function accept(Request $request, int $offerId): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::OTC_TRADE->value, $organizationId);

        // Existence is confirmed against the caller's own party list first, so
        // an offer between two strangers is 404 rather than a state error that
        // would reveal it exists.
        $this->offerOrNotFound($offerId, $organizationId);

        $trade = $this->otc->accept($offerId, $organizationId, $this->userId($request));

        return ApiResponse::item(new TradeResource(
            $trade,
            $organizationId,
            $this->instruments->find((int) $trade->instrument_id)?->code,
        ), 201);
    }

    public function counter(CounterOtcOfferRequest $request, int $offerId): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::OTC_TRADE->value, $organizationId);

        $this->offerOrNotFound($offerId, $organizationId);

        $offer = $this->otc->counter(
            offerId: $offerId,
            actorOrganizationId: $organizationId,
            userId: $this->userId($request),
            quantity: FineWeight::fromMilligrams((int) $request->validated('quantity_mg')),
            price: PricePerFineGram::fromRial((int) $request->validated('price_rial')),
            note: $request->validated('note'),
        );

        return ApiResponse::item(new OtcOfferResource(
            $offer,
            $organizationId,
            $this->instruments->find((int) $offer->instrument_id)?->code,
        ));
    }

    public function reject(RejectOtcOfferRequest $request, int $offerId): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::OTC_TRADE->value, $organizationId);

        $this->offerOrNotFound($offerId, $organizationId);

        $offer = $this->otc->reject(
            $offerId,
            $organizationId,
            $this->userId($request),
            $request->validated('reason'),
        );

        return ApiResponse::item(new OtcOfferResource($offer, $organizationId));
    }

    public function cancel(RejectOtcOfferRequest $request, int $offerId): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, Permission::OTC_TRADE->value, $organizationId);

        $this->offerOrNotFound($offerId, $organizationId);

        $offer = $this->otc->cancel(
            $offerId,
            $organizationId,
            $this->userId($request),
            $request->validated('reason'),
        );

        return ApiResponse::item(new OtcOfferResource($offer, $organizationId));
    }

    private function offerOrNotFound(int $offerId, int $organizationId): OtcOffer
    {
        return $this->query->otcOffer($offerId, $organizationId) ?? throw $this->notFound();
    }
}
