<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Middleware\RequireTransactionSignature;
use App\Modules\Shared\Http\Support\Cursor;
use App\Modules\Shared\Support\JalaliDate;
use App\Modules\Shared\Support\SettingsRepository;
use App\Support\EnumCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `/meta/*` — docs/05-api/02-endpoints.md §2.17.
 *
 * Lives in the application layer rather than in a module because it aggregates
 * across every module, which no module is permitted to do (see the dependency
 * graph in tests/Architecture/ArchitectureTest.php).
 */
final class MetaController
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * Every enum in the platform, with Persian labels.
     *
     * The point of this endpoint (stated in §2.17) is that adding an enum value
     * must not require a new mobile build — so the catalogue is discovered from
     * the code rather than maintained by hand, and it carries an ETag so a
     * client that already has the current version pays nothing to check.
     */
    public function enums(Request $request): JsonResponse
    {
        $catalogue = EnumCatalogue::all();
        $version = EnumCatalogue::version();
        $etag = '"'.$version.'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response()->json(null, 304)->setEtag($version);
        }

        return ApiResponse::item($catalogue, meta: ['version' => $version])
            ->setEtag($version)
            ->header('Cache-Control', 'private, max-age=3600');
    }

    /**
     * CONTRACT GAP RESOLVED (#4). The body of `GET /meta/settings` was never
     * specified, so the client hard-coded the numbers it needed and would have
     * silently disagreed with the server the day an operator changed one.
     *
     * The shape is now: named groups of INTEGER-scaled parameters, plus the
     * protocol constants the client must agree with the server on (idempotency,
     * transaction signing, pagination bounds, upload limits).
     *
     * Three rules this endpoint follows:
     *
     * 1. **Scaled integers only.** Rates are x100k, basis points are x10k,
     *    purity is x10k, weights are milligrams. No value here is ever a float,
     *    for the same reason no value anywhere else is.
     * 2. **Only what a client legitimately needs.** Operational thresholds that
     *    exist to catch abuse — AML rule parameters, risk ladders — are NOT
     *    published. Telling a client the structuring threshold is telling it
     *    what to stay under.
     * 3. **`overrides` is the runtime-editable layer**, read from
     *    `system_settings`. Anything present there wins over the compiled
     *    default of the same name, and the client is told both so it can show
     *    an operator-changed value as such.
     */
    public function settings(Request $request): JsonResponse
    {
        $payload = [
            'units' => [
                'mg_per_gram' => (int) config('goldb2b.units.mg_per_gram'),
                'purity_scale' => (int) config('goldb2b.units.purity_scale'),
                'rate_scale' => (int) config('goldb2b.units.rate_scale'),
                'bps_scale' => (int) config('goldb2b.units.bps_scale'),
                'mesghal_mg_x10' => (int) config('goldb2b.units.mesghal_mg_x10'),
                'ounce_mg_x10000' => (int) config('goldb2b.units.ounce_mg_x10000'),
            ],

            'market' => [
                'timezone' => (string) config('goldb2b.market.timezone'),
                'pre_open_time' => (string) config('goldb2b.market.pre_open_time'),
                'open_time' => (string) config('goldb2b.market.open_time'),
                'close_time' => (string) config('goldb2b.market.close_time'),
            ],

            'fees' => [
                'default_taker_x100k' => (int) config('goldb2b.fees.default_taker_x100k'),
                'default_maker_x100k' => (int) config('goldb2b.fees.default_maker_x100k'),
            ],

            'pricing' => [
                'max_staleness_seconds' => (int) config('goldb2b.pricing.max_staleness_seconds'),
                'max_order_deviation_bps' => (int) config('goldb2b.pricing.max_order_deviation_bps'),
                'circuit_breaker_bps' => (int) config('goldb2b.pricing.circuit_breaker_bps'),
            ],

            'settlement' => [
                'default_deadline_hours' => (int) config('goldb2b.settlement.default_deadline_hours'),
                'completion_window_hours' => (int) config('goldb2b.settlement.completion_window_hours'),
                'penalty_daily_x100k' => (int) config('goldb2b.settlement.penalty_daily_x100k'),
                'penalty_cap_x100k' => (int) config('goldb2b.settlement.penalty_cap_x100k'),
            ],

            'dispute' => [
                'reply_deadline_hours' => (int) config('goldb2b.dispute.reply_deadline_hours'),
                'negotiation_hours' => (int) config('goldb2b.dispute.negotiation_hours'),
            ],

            // Protocol-level facts the client cannot guess and must not get
            // wrong. `transaction_signing.header` is the answer to the gap that
            // made the shipped client invent a `transaction_code` body field.
            'api' => [
                'version' => 'v1',
                'pagination' => [
                    'default_limit' => Cursor::DEFAULT_LIMIT,
                    'max_limit' => Cursor::MAX_LIMIT,
                    'cursor_parameter' => 'cursor',
                ],
                'idempotency' => [
                    'header' => 'Idempotency-Key',
                    'ttl_hours' => (int) config('goldb2b.idempotency.ttl_hours'),
                    'format' => 'uuid',
                ],
                'transaction_signing' => [
                    'header' => RequireTransactionSignature::HEADER,
                    'methods' => ['TOTP'],
                    'digits' => 6,
                    'period_seconds' => 30,
                    'legacy_body_field' => RequireTransactionSignature::LEGACY_BODY_FIELD,
                    'legacy_body_field_deprecated' => true,
                ],
                'display_fields' => [
                    'suppress_with' => 'include_display=false',
                    'suffixes' => ['_display', '_jalali'],
                ],
            ],

            'uploads' => [
                'max_size_bytes' => 10 * 1024 * 1024,
                'allowed_mime_types' => ['image/jpeg', 'image/png', 'application/pdf'],
            ],

            'overrides' => $this->overrides(),
        ];

        $version = substr(hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE) ?: ''), 0, 16);

        return ApiResponse::item($payload, meta: ['version' => $version])
            ->setEtag($version)
            ->header('Cache-Control', 'private, max-age=300');
    }

    /**
     * The working calendar: which of the next N days the market trades.
     *
     * Jalali dates are returned alongside the Gregorian ones because every
     * calendar the member reads is شمسی (§1.12).
     */
    public function calendar(Request $request): JsonResponse
    {
        $days = min(90, max(1, (int) $request->query('days', '30')));
        $timezone = (string) config('goldb2b.market.timezone', 'Asia/Tehran');

        $today = CarbonImmutable::now($timezone)->startOfDay();
        $entries = [];

        for ($offset = 0; $offset < $days; $offset++) {
            $day = $today->addDays($offset);

            // Iranian weekend: Thursday and Friday.
            $isWeekend = in_array((int) $day->format('N'), [4, 5], true);

            $jalali = JalaliDate::fromGregorian(
                (int) $day->format('Y'),
                (int) $day->format('n'),
                (int) $day->format('j'),
            );

            $entries[] = [
                'date' => $day->toDateString(),
                'date_jalali' => $jalali->format(),
                'weekday' => (int) $day->format('N'),
                'is_trading_day' => ! $isWeekend,
                'opens_at' => $isWeekend ? null : (string) config('goldb2b.market.open_time'),
                'closes_at' => $isWeekend ? null : (string) config('goldb2b.market.close_time'),
            ];
        }

        return ApiResponse::collection($entries, meta: [
            'timezone' => $timezone,
            // Official public holidays are not modelled yet; the client must
            // not assume this list already excludes them.
            'includes_public_holidays' => false,
        ]);
    }

    /**
     * Operator-editable settings, whitelisted by prefix.
     *
     * `system_settings` also holds internal thresholds; only the `public.`
     * namespace is exposed, so adding an operational knob cannot accidentally
     * publish it.
     *
     * @return array<string, mixed>
     */
    private function overrides(): array
    {
        $rows = DB::table('system_settings')
            ->where('key', 'like', 'public.%')
            ->get(['key', 'value', 'value_type']);

        $overrides = [];

        foreach ($rows as $row) {
            $overrides[substr((string) $row->key, strlen('public.'))] =
                $this->settings->get((string) $row->key);
        }

        return $overrides;
    }
}
