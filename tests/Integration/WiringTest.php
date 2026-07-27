<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Accounting\Contracts\MarketPriceProvider;
use App\Modules\Broadcasting\Contracts\ThrottledBroadcast;
use App\Modules\Ledger\Contracts\GroupWriter;
use App\Modules\Notification\Contracts\NotificationChannel;
use App\Modules\Pricing\Contracts\PriceDriverInterface;
use App\Modules\Reporting\Contracts\ReportingDataSource;
use App\Modules\Risk\Contracts\MemberActivityReaderInterface;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * Two ways this platform breaks without anything failing.
 *
 * ONE — a listener registered against an event class that does not exist.
 * Laravel matches listeners on the class name and never complains about a name
 * nobody dispatches, which is exactly what makes cross-module wiring by string
 * possible here. The cost is that a typo, a rename or a class that was only
 * ever planned produces a listener that can never fire, and looks identical to
 * one that works. Seven of them were live at once: assay variance notified
 * nobody, a new OTC offer reached no counterparty, an accepted RFQ quote told
 * no one, because the events were called OtcOfferCreated and RfqAccepted and
 * the registrations said OtcOfferReceived and RfqQuoteAccepted.
 *
 * TWO — a contract still resolving to its Null implementation. Every module
 * that needs a neighbour declares an interface and binds a Null version so it
 * stays runnable while that neighbour is being built. That is the right way to
 * assemble a system concurrently, and it leaves exactly one failure mode: the
 * neighbour arrives and nobody re-points the binding. Dispute's
 * TradePartiesProvider sat on Null long after Trading was finished, and since
 * DisputeService correctly treats "I cannot vouch for that trade" as a refusal,
 * no trade-linked dispute could be opened at all. Risk's status reader was
 * worse: it answered ACTIVE for every organisation, so the pre-trade membership
 * and licence checks passed for suspended members.
 *
 * Neither is visible to a module's own suite, which builds the world it needs
 * and installs its own doubles — correct for testing a module, and blind to how
 * the application is wired. So this test asks the booted application.
 */
final class WiringTest extends TestCase
{
    /**
     * Contracts that are SUPPOSED to have no default implementation, because
     * they are implemented per instance rather than resolved from the container:
     * a marker on broadcast events, a writer handed to a postGroup callback, a
     * channel driver chosen by name, a price driver chosen by config.
     *
     * @var list<class-string>
     */
    private const NOT_CONTAINER_RESOLVED = [
        ThrottledBroadcast::class,
        GroupWriter::class,
        NotificationChannel::class,
        PriceDriverInterface::class,
    ];

    /**
     * Contracts whose Null binding is the honest answer, with the reason.
     *
     * A capability that genuinely does not exist belongs here, named and
     * explained. What must never happen is a Null binding nobody has looked at.
     *
     * @var array<class-string, string>
     */
    private const INTENTIONALLY_ABSENT = [
        MarketPriceProvider::class => 'Feeds only the informational unrealised P&L (F17), which never touches the journal. '
            .'Accounting may not depend on Pricing and Pricing may depend on nothing but Shared, so '
            .'neither module can bind it; the figure reports "no market price available" instead of '
            .'inventing one.',

        ReportingDataSource::class => 'Reporting reads across every module and may depend on none of them. Until a composition-'
            .'root adapter is written, reports render empty rather than wrong.',

        MemberActivityReaderInterface::class => 'Feeds the behavioural AML rules with login and device history. Identity records sessions '
            .'but publishes no activity read model, so the behavioural rules abstain; the transaction-'
            .'shape rules, which are the ones with teeth, do not use it.',
    ];

    #[Test]
    public function no_listener_waits_for_an_event_that_does_not_exist(): void
    {
        $dead = [];

        foreach (Event::getRawListeners() as $event => $handlers) {
            if (! is_string($event) || ! str_starts_with($event, 'App\\Modules\\')) {
                continue;
            }

            if (class_exists($event)) {
                continue;
            }

            $dead[$event] = array_map(
                static fn (mixed $handler): string => is_string($handler) ? $handler : '(closure)',
                (array) $handlers,
            );
        }

        $this->assertSame([], $dead, $this->explainDead($dead));
    }

    #[Test]
    public function no_contract_is_still_answered_by_a_null_implementation(): void
    {
        $unexpected = [];

        foreach ($this->publishedContracts() as $contract) {
            if (in_array($contract, self::NOT_CONTAINER_RESOLVED, true)) {
                continue;
            }

            try {
                $concrete = $this->app->make($contract)::class;
            } catch (Throwable) {
                // Not resolvable and not declared as such: worth knowing about,
                // but the test above this one is about wiring, not about
                // constructor arguments. Left to the next assertion.
                continue;
            }

            $short = class_basename($concrete);

            if (! str_starts_with($short, 'Null') && ! str_starts_with($short, 'Stub')) {
                continue;
            }

            if (array_key_exists($contract, self::INTENTIONALLY_ABSENT)) {
                continue;
            }

            $unexpected[$contract] = $concrete;
        }

        $this->assertSame([], $unexpected, $this->explainNull($unexpected));
    }

    #[Test]
    public function every_published_contract_can_be_resolved_or_is_declared_unresolvable(): void
    {
        $broken = [];

        foreach ($this->publishedContracts() as $contract) {
            if (in_array($contract, self::NOT_CONTAINER_RESOLVED, true)) {
                continue;
            }

            try {
                $this->app->make($contract);
            } catch (Throwable $e) {
                $broken[$contract] = substr($e->getMessage(), 0, 120);
            }
        }

        $this->assertSame(
            [],
            $broken,
            "These contracts are published but nothing can build them:\n".
            json_encode($broken, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).
            "\n\nBind a concrete class in the owning module's provider, or add the contract to ".
            'NOT_CONTAINER_RESOLVED if it is implemented per instance rather than resolved.',
        );
    }

    #[Test]
    public function the_exemption_lists_do_not_outlive_their_reason(): void
    {
        // An exemption for a contract that no longer exists, or that is no
        // longer Null, is a note that has stopped being true. Left alone, the
        // lists slowly become a place where real problems can hide.
        foreach ([...array_keys(self::INTENTIONALLY_ABSENT), ...self::NOT_CONTAINER_RESOLVED] as $contract) {
            $this->assertTrue(
                interface_exists($contract),
                "{$contract} is exempted here but no longer exists. Remove the entry.",
            );
        }

        foreach (array_keys(self::INTENTIONALLY_ABSENT) as $contract) {
            $concrete = class_basename($this->app->make($contract)::class);

            $this->assertTrue(
                str_starts_with($concrete, 'Null') || str_starts_with($concrete, 'Stub'),
                "{$contract} now resolves to {$concrete}. It is wired — remove it from ".
                'INTENTIONALLY_ABSENT so a future regression to Null is caught.',
            );
        }
    }

    /**
     * Every interface under a module's Contracts directory.
     *
     * @return list<class-string>
     */
    private function publishedContracts(): array
    {
        $contracts = [];

        foreach (glob(base_path('app/Modules/*/Contracts/*.php')) ?: [] as $file) {
            $class = sprintf(
                'App\\Modules\\%s\\Contracts\\%s',
                basename(dirname($file, 2)),
                basename($file, '.php'),
            );

            if (interface_exists($class)) {
                $contracts[] = $class;
            }
        }

        sort($contracts);

        return $contracts;
    }

    /** @param array<string, list<string>> $dead */
    private function explainDead(array $dead): string
    {
        if ($dead === []) {
            return '';
        }

        return "These listeners can never fire — the event class does not exist:\n".
            json_encode($dead, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).
            "\n\nCheck the producing module for the event's real name. If the capability is not built ".
            'yet, remove the registration rather than leaving wiring that looks live.';
    }

    /** @param array<string, string> $unexpected */
    private function explainNull(array $unexpected): string
    {
        if ($unexpected === []) {
            return '';
        }

        return "These contracts still resolve to a Null/Stub implementation:\n".
            json_encode($unexpected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).
            "\n\nIf the providing module now exists, bind the real adapter. If the capability is ".
            'genuinely absent, add the contract to INTENTIONALLY_ABSENT with the reason — an '.
            'unexamined Null binding is a feature that silently does nothing.';
    }
}
