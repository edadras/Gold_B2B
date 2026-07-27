<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule as ScheduleDays;
use Illuminate\Support\Facades\Schedule;

/*
 * Scheduled work — docs/02-architecture/03-events.md §3.6.
 *
 * Times are expressed in the market timezone rather than UTC: a task tied to
 * the trading day must follow the trading day, not drift with daylight rules
 * elsewhere.
 *
 * Every job is withoutOverlapping(): these are financial reconciliations and
 * expiry sweeps, and a second copy starting while the first is still running
 * would double-apply penalties or fight for the same locks. onOneServer()
 * matters once more than one scheduler host exists; it is harmless with one.
 */

$tz = config('goldb2b.market.timezone', 'Asia/Tehran');

// ── Market sessions ──────────────────────────────────────────────────────────
// Weekdays in Iran run Saturday to Wednesday, with Thursday a short day and
// Friday closed. The business_calendar table is authoritative for holidays;
// these bounds only decide when to *attempt* an open, and market:open itself
// refuses on a non-working day.
Schedule::command('market:open')
    ->timezone($tz)
    ->dailyAt(config('goldb2b.market.open_time', '09:00'))
    ->days([ScheduleDays::SATURDAY, ScheduleDays::SUNDAY, ScheduleDays::MONDAY, ScheduleDays::TUESDAY, ScheduleDays::WEDNESDAY])
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('market:close')
    ->timezone($tz)
    ->dailyAt(config('goldb2b.market.close_time', '17:30'))
    ->days([ScheduleDays::SATURDAY, ScheduleDays::SUNDAY, ScheduleDays::MONDAY, ScheduleDays::TUESDAY, ScheduleDays::WEDNESDAY])
    ->withoutOverlapping()
    ->onOneServer();

// ── Pricing ──────────────────────────────────────────────────────────────────
// The reference price drives the fat-finger guard and the circuit breaker, so
// it is polled continuously; the ingestion layer discards stale and outlier
// ticks itself.
Schedule::command('pricing:fetch-reference')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('pricing:build-ohlc')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// ── Order and quote expiry ───────────────────────────────────────────────────
Schedule::command('orders:expire')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('rfq:expire-stale')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// ── Settlement ───────────────────────────────────────────────────────────────
// The overdue ladder accrues penalties and escalates to default, so it must run
// on a tight cadence; a missed hour is a missed escalation.
Schedule::command('settlement:check-overdue')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('dispute:process-deadlines')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// ── Nightly reconciliation ───────────────────────────────────────────────────
// ledger:reconcile is the single mechanism that detects a divergence between
// the entries and the cached balances, and between the ledger and physical
// stock. If it stops running, a discrepancy stays invisible until someone
// notices their balance is wrong. Failure must page a human.
Schedule::command('ledger:reconcile')
    ->timezone($tz)
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->emailOutputOnFailure(config('goldb2b.ops.alert_email', null) ?? []);

Schedule::command('ledger:snapshot')
    ->timezone($tz)
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('counterparty:reconcile')
    ->timezone($tz)
    ->dailyAt('02:45')
    ->withoutOverlapping()
    ->onOneServer();

// ── Daily rollups and housekeeping ───────────────────────────────────────────
Schedule::command('report:build-daily')
    ->timezone($tz)
    ->dailyAt('18:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('reputation:recompute')
    ->timezone($tz)
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('kyc:check-expiring-licenses')
    ->timezone($tz)
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('notification:prune')
    ->timezone($tz)
    ->weeklyOn(ScheduleDays::FRIDAY, '04:00')
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Deliberately NOT scheduled:
 *
 *   market:halt              an emergency control, invoked by a human
 *   accounting:close-period  a period close is an accounting decision, not a
 *                            clock event; closing automatically would lock a
 *                            period an operator may still need to post into
 */
