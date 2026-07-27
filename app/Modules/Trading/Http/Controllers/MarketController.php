<?php

declare(strict_types=1);

namespace App\Modules\Trading\Http\Controllers;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Trading\Application\InstrumentRepository;
use App\Modules\Trading\Application\MarketSessionService;
use App\Modules\Trading\Application\OrderBookReader;
use App\Modules\Trading\Application\TradingQueryService;
use App\Modules\Trading\Http\Requests\MarketDepthRequest;
use App\Modules\Trading\Http\Resources\InstrumentResource;
use App\Modules\Trading\Http\Resources\MarketDepthResource;
use App\Modules\Trading\Http\Resources\MarketSessionResource;
use App\Modules\Trading\Infrastructure\Models\Instrument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/instruments` and `/market/*` — docs/05-api/02-endpoints.md §2.4.
 *
 * These are the only endpoints in the platform that are NOT tenant-scoped, and
 * deliberately so: the order book is a shared venue and every member sees the
 * same depth, the same tape and the same session state. What makes that safe is
 * that none of these payloads names an organisation. The permission check is
 * still performed — ORDER_BOOK_VIEW is what a VIEWER-and-above holds — so the
 * data is not public, just not partitioned.
 *
 * On the `market-data` limiter (300/minute) rather than `api`: a client with a
 * live depth widget polls far harder than one filling in a form.
 */
final class MarketController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly InstrumentRepository $instruments,
        private readonly OrderBookReader $book,
        private readonly MarketSessionService $sessions,
        private readonly TradingQueryService $query,
    ) {
        parent::__construct($authorization);
    }

    public function instruments(Request $request): JsonResponse
    {
        $this->permit($request, Permission::ORDER_BOOK_VIEW->value);

        return ApiResponse::collection(
            InstrumentResource::collection($this->instruments->active()),
        );
    }

    public function instrument(Request $request, string $code): JsonResponse
    {
        $this->permit($request, Permission::ORDER_BOOK_VIEW->value);

        return ApiResponse::item(new InstrumentResource($this->resolve($code)));
    }

    /**
     * Aggregated depth. The snapshot this returns is built by OrderBookReader,
     * which never selects an owner column — so there is no identity in the
     * payload to strip, rather than an identity we remembered to strip.
     */
    public function depth(MarketDepthRequest $request, string $code): JsonResponse
    {
        $this->permit($request, Permission::ORDER_BOOK_VIEW->value);

        $depth = $this->book->depth($this->resolve($code), $request->levels());

        return ApiResponse::item(new MarketDepthResource($depth));
    }

    /** The tape: price, size, time and which side was passive. No identities. */
    public function trades(Request $request, string $code): JsonResponse
    {
        $this->permit($request, Permission::ORDER_BOOK_VIEW->value);

        $instrument = $this->resolve($code);
        $limit = min(200, max(1, (int) $request->query('limit', '50')));

        return ApiResponse::collection($this->query->tape((int) $instrument->id, $limit));
    }

    public function session(Request $request, string $code): JsonResponse
    {
        $this->permit($request, Permission::ORDER_BOOK_VIEW->value);

        $instrument = $this->resolve($code);
        $session = $this->sessions->currentSession((int) $instrument->id);

        if ($session === null) {
            // No scheduled session today is a legitimate state, not an error:
            // the client renders "بازار بسته است" from it.
            return ApiResponse::item([
                'instrument' => $instrument->code,
                'status' => 'CLOSED',
                'session_date' => null,
                'is_matching' => false,
            ]);
        }

        return ApiResponse::item([
            'instrument' => $instrument->code,
            'is_matching' => $this->sessions->isMatching((int) $instrument->id),
        ] + (new MarketSessionResource($session))->toArray($request));
    }

    /** Unknown instrument code is a 404, like any other missing resource. */
    private function resolve(string $code): Instrument
    {
        return $this->instruments->findByCode($code) ?? throw $this->notFound();
    }
}
