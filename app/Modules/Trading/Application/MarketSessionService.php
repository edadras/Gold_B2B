<?php

declare(strict_types=1);

namespace App\Modules\Trading\Application;

use App\Modules\Shared\Exceptions\InvalidStateTransitionException;
use App\Modules\Shared\Support\SettingsRepository;
use App\Modules\Trading\Domain\Exceptions\MarketClosedException;
use App\Modules\Trading\Domain\Exceptions\TradingEntityNotFoundException;
use App\Modules\Trading\Domain\MarketSessionStatus;
use App\Modules\Trading\Events\MarketPaused;
use App\Modules\Trading\Events\MarketSessionClosed;
use App\Modules\Trading\Events\MarketSessionOpened;
use App\Modules\Trading\Infrastructure\Models\Instrument;
use App\Modules\Trading\Infrastructure\Models\MarketSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The trading calendar: pre-open, open, pause, close (docs/03-domain/04-trading.md §4.8).
 *
 * assertOpen() is the gate PlaceOrderService calls before anything else. It
 * runs outside the placement transaction, which is exactly right: a closed
 * market is a validation failure, not a rollback.
 *
 * PRE_OPEN accepts orders but does not match them; the state enum carries that
 * distinction (acceptsOrders() vs matches()) so no caller has to remember it.
 */
final class MarketSessionService
{
    private const DEFAULT_PAUSE_MINUTES = 15;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly OrderExpiryService $expiry,
    ) {}

    /**
     * @throws MarketClosedException when orders may not be entered right now
     */
    public function assertOpen(Instrument $instrument): MarketSession
    {
        if (! $instrument->isTradable()) {
            throw new MarketClosedException($instrument->code, $instrument->status->value);
        }

        $session = $this->currentSession($instrument->id);

        if ($session === null || ! $session->status->acceptsOrders()) {
            throw new MarketClosedException(
                $instrument->code,
                $session?->status->value ?? 'NO_SESSION',
            );
        }

        return $session;
    }

    /** True when incoming orders should actually be matched rather than parked. */
    public function isMatching(int $instrumentId): bool
    {
        return $this->currentSession($instrumentId)?->status->matches() === true;
    }

    /** Today's session for the instrument, or null when none was scheduled. */
    public function currentSession(int $instrumentId, ?CarbonImmutable $at = null): ?MarketSession
    {
        $day = ($at ?? CarbonImmutable::now())->toDateString();

        return MarketSession::query()
            ->where('instrument_id', $instrumentId)
            ->whereDate('session_date', $day)
            ->first();
    }

    public function sessionOrFail(int $instrumentId, ?CarbonImmutable $at = null): MarketSession
    {
        return $this->currentSession($instrumentId, $at)
            ?? throw TradingEntityNotFoundException::marketSession($instrumentId);
    }

    /**
     * Create today's session in SCHEDULED, or return the one already there.
     * Idempotent so the scheduler may run it more than once.
     */
    public function schedule(Instrument $instrument, ?CarbonImmutable $day = null): MarketSession
    {
        $day ??= CarbonImmutable::now();
        $date = $day->toDateString();

        $existing = $this->currentSession($instrument->id, $day);

        if ($existing !== null) {
            return $existing;
        }

        return MarketSession::create([
            'instrument_id' => $instrument->id,
            'session_date' => $date,
            'status' => MarketSessionStatus::SCHEDULED,
            'pre_open_at' => $this->timeOn($day, 'market.pre_open_time', 'pre_open_time', '08:45'),
            'opens_at' => $this->timeOn($day, 'market.open_time', 'open_time', '09:00'),
            'closes_at' => $this->timeOn($day, 'market.close_time', 'close_time', '17:30'),
        ]);
    }

    /** SCHEDULED -> PRE_OPEN. Orders may be entered; nothing is matched. */
    public function preOpen(Instrument $instrument, ?CarbonImmutable $day = null): MarketSession
    {
        $session = $this->schedule($instrument, $day);

        $this->apply($session, MarketSessionStatus::PRE_OPEN);

        return $session;
    }

    /**
     * PRE_OPEN|PAUSED -> OPEN. Continuous trading begins (or resumes, in which
     * case §4.8 calls for a re-opening auction — see the note below).
     */
    public function open(Instrument $instrument, ?CarbonImmutable $day = null): MarketSession
    {
        $session = $this->schedule($instrument, $day);
        $resumed = $session->status === MarketSessionStatus::PAUSED;

        if ($session->status === MarketSessionStatus::SCHEDULED) {
            $this->apply($session, MarketSessionStatus::PRE_OPEN);
        }

        $this->apply($session, MarketSessionStatus::OPEN, [
            'opened_at' => $session->opened_at ?? now(),
            'paused_at' => null,
            'resume_at' => null,
            'pause_reason' => null,
        ]);

        // The opening auction of §4.8 batches the pre-open book at a single
        // clearing price. Continuous matching is what this module implements;
        // the auction is deferred and tracked as unfinished work rather than
        // faked with a continuous sweep that would print several prices.
        event(new MarketSessionOpened(
            sessionId: $session->id,
            instrumentId: $session->instrument_id,
            sessionDate: $session->session_date->toDateString(),
            openingPriceRial: $session->opening_price_rial,
            resumedFromPause: $resumed,
            occurredAt: now()->toIso8601String(),
        ));

        return $session;
    }

    /**
     * OPEN -> PAUSED. Circuit breaker, missing price, or a manual halt.
     *
     * Idempotent: a session already paused stays paused and no second event is
     * fired, because both the breaker and the no-price watchdog can trip in the
     * same minute.
     */
    public function pause(
        int $instrumentId,
        string $reasonCode,
        string $reason,
        ?int $minutes = null,
    ): ?MarketSession {
        $session = $this->currentSession($instrumentId);

        if ($session === null || $session->status === MarketSessionStatus::PAUSED) {
            return $session;
        }

        if (! $session->status->canTransitionTo(MarketSessionStatus::PAUSED)) {
            return $session;
        }

        $resumeAt = $minutes === null ? null : now()->addMinutes($minutes);

        $this->apply($session, MarketSessionStatus::PAUSED, [
            'paused_at' => now(),
            'resume_at' => $resumeAt,
            'pause_reason' => $reason,
        ]);

        event(new MarketPaused(
            sessionId: $session->id,
            instrumentId: $session->instrument_id,
            reasonCode: $reasonCode,
            reason: $reason,
            resumeAt: $resumeAt?->toIso8601String(),
            occurredAt: now()->toIso8601String(),
        ));

        return $session;
    }

    /** The circuit breaker of §4.8: a fixed pause, then a re-opening auction. */
    public function pauseForCircuitBreaker(int $instrumentId, int $deviationBps, int $thresholdBps): ?MarketSession
    {
        return $this->pause(
            $instrumentId,
            MarketPaused::REASON_CIRCUIT_BREAKER,
            sprintf('Circuit breaker: deviation %d bps exceeded threshold %d bps', $deviationBps, $thresholdBps),
            $this->pauseMinutes(),
        );
    }

    /** No reference price for longer than the configured gap — pause open-ended. */
    public function pauseForMissingPrice(int $instrumentId, ?int $secondsSinceLastPrice): ?MarketSession
    {
        return $this->pause(
            $instrumentId,
            MarketPaused::REASON_NO_PRICE,
            $secondsSinceLastPrice === null
                ? 'No reference price available'
                : sprintf('No reference price for %d seconds', $secondsSinceLastPrice),
            null,
        );
    }

    /**
     * -> CLOSED. DAY orders are cancelled and their reservations released; GTC
     * orders survive to the next session (§4.8).
     */
    public function close(int $instrumentId, ?int $closingPriceRial = null): ?MarketSession
    {
        $session = $this->currentSession($instrumentId);

        if ($session === null || $session->status === MarketSessionStatus::CLOSED) {
            return $session;
        }

        $cancelled = $this->expiry->cancelDayOrders($instrumentId);

        $this->apply($session, MarketSessionStatus::CLOSED, [
            'closed_at' => now(),
            'closing_price_rial' => $closingPriceRial ?? $session->closing_price_rial,
        ]);

        event(new MarketSessionClosed(
            sessionId: $session->id,
            instrumentId: $session->instrument_id,
            sessionDate: $session->session_date->toDateString(),
            closingPriceRial: $session->closing_price_rial,
            volumeMg: $session->volume_mg,
            tradeCount: $session->trade_count,
            cancelledDayOrders: $cancelled,
            occurredAt: now()->toIso8601String(),
        ));

        return $session;
    }

    /**
     * Fold one execution into the session's OHLCV. Called from inside the
     * matching transaction, hence the row lock.
     */
    public function recordTrade(int $instrumentId, int $priceRial, int $quantityMg): void
    {
        $session = MarketSession::query()
            ->where('instrument_id', $instrumentId)
            ->whereDate('session_date', CarbonImmutable::now()->toDateString())
            ->lockForUpdate()
            ->first();

        if ($session === null) {
            return;
        }

        $session->opening_price_rial ??= $priceRial;
        $session->closing_price_rial = $priceRial;
        $session->high_price_rial = max($session->high_price_rial ?? $priceRial, $priceRial);
        $session->low_price_rial = min($session->low_price_rial ?? $priceRial, $priceRial);
        $session->volume_mg += $quantityMg;
        $session->trade_count++;
        $session->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidStateTransitionException
     */
    private function apply(MarketSession $session, MarketSessionStatus $target, array $attributes = []): void
    {
        if ($session->status === $target) {
            $session->fill($attributes)->save();

            return;
        }

        if (! $session->status->canTransitionTo($target)) {
            throw new InvalidStateTransitionException(
                'MarketSession',
                $session->status->value,
                $target->value,
            );
        }

        DB::transaction(function () use ($session, $target, $attributes): void {
            $session->fill($attributes);
            $session->status = $target;
            $session->save();
        });
    }

    private function pauseMinutes(): int
    {
        $configured = config('goldb2b.trading.circuit_breaker_pause_minutes');

        return is_int($configured) ? $configured : self::DEFAULT_PAUSE_MINUTES;
    }

    private function timeOn(CarbonImmutable $day, string $settingKey, string $configKey, string $fallback): string
    {
        $stored = $this->settings->get($settingKey);
        $time = is_string($stored) && $stored !== ''
            ? $stored
            : (string) (config('goldb2b.market.'.$configKey) ?? $fallback);

        [$hour, $minute] = array_pad(explode(':', $time, 2), 2, '0');

        return $day->setTime((int) $hour, (int) $minute)->toDateTimeString();
    }
}
