<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Controllers;

use App\Modules\Pricing\Application\PriceAlertManager;
use App\Modules\Pricing\Contracts\InstrumentDirectory;
use App\Modules\Pricing\Http\Requests\StorePriceAlertRequest;
use App\Modules\Pricing\Http\Resources\PriceAlertResource;
use App\Modules\Pricing\Infrastructure\Models\PriceAlert;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `/price-alerts` — §2.4. */
final class PriceAlertController extends ApiController
{
    /**
     * The permission name, as a literal.
     *
     * Every other module names permissions through Identity's Permission enum,
     * but Pricing is only permitted to depend on Shared (see the module graph
     * in tests/Architecture/ArchitectureTest.php), so it cannot import it. The
     * dotted string is safe to hard-code: Permission's own docblock states that
     * the value is part of the data contract and that renaming one requires a
     * migration, so this literal cannot silently drift.
     */
    private const VIEW_MARKET = 'orderbook.view';

    public function __construct(
        AuthorizationGateway $authorization,
        private readonly PriceAlertManager $alerts,
        private readonly InstrumentDirectory $instruments,
    ) {
        parent::__construct($authorization);
    }

    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        // Both legs: ORDER_BOOK_VIEW is the role grant, and the query is scoped
        // to the caller's organisation AND to the caller's own user id. Alerts
        // are personal — a colleague's watchlist is not shared inside the
        // member — so tenancy alone would still be too broad here.
        $this->permit($request, self::VIEW_MARKET, $organizationId);

        $alerts = $this->alerts->forUser($organizationId, $this->userId($request));

        return ApiResponse::collection(
            $alerts->map(fn (PriceAlert $alert): PriceAlertResource => new PriceAlertResource(
                $alert,
                $this->instruments->codeForId((int) $alert->instrument_id),
            ))->all(),
        );
    }

    public function store(StorePriceAlertRequest $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, self::VIEW_MARKET, $organizationId);

        $userId = $this->userId($request);
        $code = (string) $request->validated('instrument');

        $instrumentId = $this->instruments->idForCode($code) ?? throw $this->notFound();

        if ($this->alerts->countFor($organizationId, $userId) >= PriceAlertManager::MAX_PER_USER) {
            return ApiResponse::error(
                'LIMIT_EXCEEDED',
                'حداکثر تعداد هشدار فعال را ثبت کرده‌اید.',
                422,
                ['limit' => PriceAlertManager::MAX_PER_USER],
            );
        }

        $alert = $this->alerts->create(
            organizationId: $organizationId,
            userId: $userId,
            instrumentId: $instrumentId,
            condition: $request->condition(),
            threshold: (int) $request->validated('threshold'),
            windowSeconds: (int) $request->validated('window_seconds', 3600),
            isRecurring: (bool) $request->validated('is_recurring', false),
        );

        return ApiResponse::item(new PriceAlertResource($alert, $code), 201);
    }

    public function destroy(Request $request, int $alertId): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $this->permit($request, self::VIEW_MARKET, $organizationId);

        // Somebody else's alert is 404, never 403.
        $alert = $this->alerts->find($alertId, $organizationId, $this->userId($request))
            ?? throw $this->notFound();

        $this->alerts->delete($alert);

        return ApiResponse::noContent();
    }
}
