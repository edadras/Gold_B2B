<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The scheduler is the only thing that makes reconciliation and expiry actually
 * happen. A command that exists but is never scheduled is a control that looks
 * present in a review and does nothing in production, so the wiring is pinned
 * here rather than trusted.
 */
final class ScheduleTest extends TestCase
{
    /** Commands whose absence would let a real problem go undetected. */
    private const MUST_BE_SCHEDULED = [
        'ledger:reconcile',
        'ledger:snapshot',
        'counterparty:reconcile',
        'settlement:check-overdue',
        'dispute:process-deadlines',
        'orders:expire',
        'rfq:expire-stale',
        'pricing:fetch-reference',
        'pricing:build-ohlc',
        'market:open',
        'market:close',
        'kyc:check-expiring-licenses',
        'report:build-daily',
        'reputation:recompute',
    ];

    /**
     * Commands that must NOT be automated, with the reason.
     *
     * @var array<string, string>
     */
    private const MUST_NOT_BE_SCHEDULED = [
        'market:halt' => 'an emergency control belongs to a human, not a clock',
        'accounting:close-period' => 'closing a period is an accounting decision; automating it would lock a period an operator may still need to post into',
    ];

    #[Test]
    public function every_critical_command_is_scheduled(): void
    {
        $scheduled = $this->scheduledCommands();

        foreach (self::MUST_BE_SCHEDULED as $command) {
            self::assertContains(
                $command,
                $scheduled,
                "{$command} is not scheduled; it would never run in production.",
            );
        }
    }

    #[Test]
    public function manual_only_commands_are_not_scheduled(): void
    {
        $scheduled = $this->scheduledCommands();

        foreach (self::MUST_NOT_BE_SCHEDULED as $command => $reason) {
            self::assertNotContains(
                $command,
                $scheduled,
                "{$command} must not be scheduled: {$reason}",
            );
        }
    }

    #[Test]
    public function financial_jobs_cannot_overlap_themselves(): void
    {
        $overlapping = [];

        foreach ($this->scheduleEvents() as $event) {
            $command = $this->commandName($event);

            if ($command === null || ! in_array($command, self::MUST_BE_SCHEDULED, true)) {
                continue;
            }

            // A second copy starting mid-run would double-apply penalties or
            // contend for the same ledger locks.
            if (! $event->withoutOverlapping) {
                $overlapping[] = $command;
            }
        }

        self::assertSame([], $overlapping, 'These jobs may run concurrently with themselves: '.implode(', ', $overlapping));
    }

    #[Test]
    public function market_sessions_follow_the_market_timezone(): void
    {
        $expected = config('goldb2b.market.timezone');

        foreach ($this->scheduleEvents() as $event) {
            if (in_array($this->commandName($event), ['market:open', 'market:close'], true)) {
                self::assertSame(
                    $expected,
                    $event->timezone,
                    'Market sessions must follow the market timezone, not the server default.',
                );
            }
        }
    }

    #[Test]
    public function reconciliation_runs_daily_not_less_often(): void
    {
        foreach ($this->scheduleEvents() as $event) {
            if ($this->commandName($event) === 'ledger:reconcile') {
                // "0 2 * * *" style: a fixed hour, every day.
                self::assertMatchesRegularExpression(
                    '/^\d+ \d+ \* \* \*$/',
                    $event->expression,
                    'ledger:reconcile must run every day.',
                );

                return;
            }
        }

        self::fail('ledger:reconcile is not scheduled at all.');
    }

    /** @return list<Event> */
    private function scheduleEvents(): array
    {
        return app(Schedule::class)->events();
    }

    /** @return list<string> */
    private function scheduledCommands(): array
    {
        return array_values(array_filter(
            array_map(fn (Event $e) => $this->commandName($e), $this->scheduleEvents())
        ));
    }

    private function commandName(Event $event): ?string
    {
        if (! preg_match("/artisan['\"]? ([a-z][a-z0-9:-]+)/i", $event->command ?? '', $m)) {
            return null;
        }

        return trim($m[1], '\'"');
    }
}
