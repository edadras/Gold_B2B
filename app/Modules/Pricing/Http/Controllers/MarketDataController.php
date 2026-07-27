<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Controllers;

use App\Modules\Pricing\Application\CandleService;
use App\Modules\Pricing\Application\QuoteService;
use App\Modules\Pricing\Contracts\InstrumentDirectory;
use App\Modules\Pricing\Http\Requests\CandleRequest;
use App\Modules\Pricing\Http\Resources\CandleResource;
use App\Modules\Pricing\Http\Resources\QuoteResource;
use App\Modules\Pricing\Infrastructure\Models\ReferencePrice;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/market/quotes`, `/market/candles`, `/market/reference-price` — §2.4.
 *
 * Market data is shared, not tenant-partitioned: every member sees the same
 * price. That is safe because none of these payloads names an organisation —
 * a quote is a price and a size, a candle is an aggregate, and the reference
 * price is derived from external feeds. The role check is still made, so the
 * data is authenticated even though it is not partitioned.
 *
 * Instrument codes are resolved through the InstrumentDirectory port rather
 * than by importing Trading, which Pricing may not depend on.
 */
final class MarketDataController extends ApiController
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
        private readonly QuoteService $quotes,
        private readonly CandleService $candles,
        private readonly InstrumentDirectory $instruments,
    ) {
        parent::__construct($authorization);
    }

    public function quotes(Request $request): JsonResponse
    {
        $this->permit($request, self::VIEW_MARKET);

        $payload = [];

        foreach ($this->instruments->activeInstrumentIds() as $instrumentId) {
            $snapshot = $this->quotes->snapshot($instrumentId);

            if ($snapshot !== null) {
                $payload[] = new QuoteResource($snapshot, $this->instruments->codeForId($instrumentId));
            }
        }

        return ApiResponse::collection($payload);
    }

    public function quote(Request $request, string $code): JsonResponse
    {
        $this->permit($request, self::VIEW_MARKET);

        $instrumentId = $this->resolve($code);
        $snapshot = $this->quotes->snapshot($instrumentId);

        if ($snapshot === null) {
            // The instrument exists but has never printed. That is a real
            // state, not an error — the client renders "بدون معامله".
            return ApiResponse::item([
                'instrument' => $code,
                'instrument_id' => $instrumentId,
                'last_price_rial' => null,
                'last_is_stale' => true,
            ]);
        }

        return ApiResponse::item(new QuoteResource($snapshot, $code));
    }

    public function candles(CandleRequest $request, string $code): JsonResponse
    {
        $this->permit($request, self::VIEW_MARKET);

        $candles = $this->candles->history(
            $this->resolve($code),
            $request->interval(),
            $request->limit(),
        );

        return ApiResponse::collection(
            CandleResource::collection($candles),
            meta: ['instrument' => $code, 'interval' => $request->interval()->value],
        );
    }

    /**
     * The intrinsic value the platform computed from the ounce price and the
     * FX rate (F-series). Read-only: computing a fresh one writes a row and is
     * the scheduled command's job, not a GET's.
     */
    public function referencePrice(Request $request): JsonResponse
    {
        $this->permit($request, self::VIEW_MARKET);

        $code = $request->query('instrument');
        $instrumentId = is_string($code) && $code !== ''
            ? $this->resolve($code)
            : ($this->instruments->activeInstrumentIds()[0] ?? throw $this->notFound());

        /** @var ReferencePrice|null $row */
        $row = ReferencePrice::query()
            ->where('instrument_id', $instrumentId)
            ->orderByDesc('computed_at')
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            throw $this->notFound();
        }

        return ApiResponse::item([
            'instrument' => $this->instruments->codeForId($instrumentId),
            'instrument_id' => $instrumentId,
            'fine_gram_rial' => (int) $row->fine_gram_rial,
            'ounce_usd_micro' => (int) $row->ounce_usd_micro,
            'usd_irr' => (int) $row->usd_irr,
            'mode' => (string) $row->mode,
            'computed_at' => Display::iso($row->computed_at),
            'fine_gram_display' => Display::rial((int) $row->fine_gram_rial),
            'computed_at_jalali' => Display::jalali($row->computed_at),
        ]);
    }

    private function resolve(string $code): int
    {
        return $this->instruments->idForCode($code) ?? throw $this->notFound();
    }
}
