<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Tests\Feature;

use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\EntryType;
use App\Modules\Ledger\Domain\Exceptions\InvalidReleaseException;
use App\Modules\Ledger\Domain\LedgerEntryId;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Ledger\Events\ReservationReleased;
use App\Modules\Ledger\Tests\LedgerTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * Worked example 1, step 2 (g3): the buyer's surplus reservation is released
 * once the execution price is known, so a partial release must be exact.
 */
final class ReleaseTest extends LedgerTestCase
{
    use RefreshDatabase;

    private const ORG = 184;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLedger(self::ORG);
        $this->depositGold(self::ORG, 1_000_000);
    }

    #[Test]
    public function a_full_release_returns_everything_to_available(): void
    {
        $reservation = $this->goldLedger()->reserve(self::ORG, self::fine(250_000), LedgerReference::order(1));

        $this->goldLedger()->release($reservation);

        $this->assertSame(1_000_000, $this->goldBalance(self::ORG));
        $this->assertSame(0, $this->goldBalance(self::ORG, Bucket::RESERVED));
    }

    #[Test]
    public function a_partial_release_returns_only_what_was_asked_for(): void
    {
        $reservation = $this->goldLedger()->reserve(self::ORG, self::fine(250_000), LedgerReference::order(1));

        $this->goldLedger()->release($reservation, self::fine(100_000));

        $this->assertSame(850_000, $this->goldBalance(self::ORG));
        $this->assertSame(150_000, $this->goldBalance(self::ORG, Bucket::RESERVED));
    }

    #[Test]
    public function repeated_partial_releases_add_up_to_the_reservation_and_no_more(): void
    {
        $reservation = $this->goldLedger()->reserve(self::ORG, self::fine(300_000), LedgerReference::order(1));

        $this->goldLedger()->release($reservation, self::fine(100_000));
        $this->goldLedger()->release($reservation, self::fine(100_000));
        $this->goldLedger()->release($reservation, self::fine(100_000));

        $this->assertSame(1_000_000, $this->goldBalance(self::ORG));
        $this->assertSame(0, $this->goldBalance(self::ORG, Bucket::RESERVED));

        $this->expectException(InvalidReleaseException::class);
        $this->goldLedger()->release($reservation, self::fine(1));
    }

    #[Test]
    public function releasing_more_than_remains_is_rejected_and_writes_nothing(): void
    {
        $reservation = $this->goldLedger()->reserve(self::ORG, self::fine(250_000), LedgerReference::order(1));
        $before = $this->entryCount();

        try {
            $this->goldLedger()->release($reservation, self::fine(250_001));
            $this->fail('Expected InvalidReleaseException');
        } catch (InvalidReleaseException $e) {
            $this->assertSame(250_001, $e->requested);
            $this->assertSame(250_000, $e->outstanding);
        }

        $this->assertSame($before, $this->entryCount());
        $this->assertSame(250_000, $this->goldBalance(self::ORG, Bucket::RESERVED));
    }

    #[Test]
    public function releasing_a_second_time_after_a_full_release_is_rejected(): void
    {
        $reservation = $this->goldLedger()->reserve(self::ORG, self::fine(250_000), LedgerReference::order(1));
        $this->goldLedger()->release($reservation);

        $this->expectException(InvalidReleaseException::class);
        $this->goldLedger()->release($reservation);
    }

    #[Test]
    public function an_entry_that_is_not_a_reservation_cannot_be_released(): void
    {
        $depositEntryId = (int) DB::table('ledger_entries')
            ->where('entry_type', EntryType::DEPOSIT_GOLD->value)
            ->value('id');

        $this->expectException(InvalidReleaseException::class);
        $this->goldLedger()->release(LedgerEntryId::fromInt($depositEntryId));
    }

    #[Test]
    public function the_debit_leg_of_a_reservation_cannot_be_released(): void
    {
        $this->goldLedger()->reserve(self::ORG, self::fine(250_000), LedgerReference::order(1));

        $debitLegId = (int) DB::table('ledger_entries')
            ->where('entry_type', EntryType::RESERVE->value)
            ->where('amount', '<', 0)
            ->value('id');

        $this->expectException(InvalidReleaseException::class);
        $this->goldLedger()->release(LedgerEntryId::fromInt($debitLegId));
    }

    #[Test]
    public function release_writes_a_balanced_pair_tagged_with_the_reservation(): void
    {
        $reservation = $this->goldLedger()->reserve(self::ORG, self::fine(250_000), LedgerReference::order(44101));
        $before = $this->entryCount();

        $this->goldLedger()->release($reservation, self::fine(50_000));

        $this->assertSame($before + 2, $this->entryCount());

        $rows = DB::table('ledger_entries')
            ->where('entry_type', EntryType::RELEASE->value)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame(0, (int) $rows->sum('amount'));
        $this->assertGroupSumsToZero((string) $rows->first()->transaction_group);

        foreach ($rows as $row) {
            $this->assertSame(
                $reservation->value,
                json_decode((string) $row->metadata, true)['reservation_entry_id'],
            );
            // The release inherits the reservation's source document.
            $this->assertSame('order', $row->reference_type);
            $this->assertSame(44101, (int) $row->reference_id);
        }
    }

    #[Test]
    public function releasing_a_reservation_already_consumed_by_a_settlement_lock_is_rejected(): void
    {
        $reservation = $this->goldLedger()->reserve(self::ORG, self::fine(250_000), LedgerReference::order(1));

        // The order was filled: RESERVED → IN_SETTLEMENT, no RELEASE entry.
        $this->goldLedger()->moveBucket(
            self::ORG,
            Bucket::RESERVED,
            Bucket::IN_SETTLEMENT,
            self::fine(250_000),
            LedgerReference::settlement(88231),
        );

        $this->expectException(InvalidReleaseException::class);
        $this->goldLedger()->release($reservation);
    }

    #[Test]
    public function it_dispatches_reservation_released(): void
    {
        $reservation = $this->goldLedger()->reserve(self::ORG, self::fine(250_000), LedgerReference::order(1));

        Event::fake([ReservationReleased::class]);

        $this->goldLedger()->release($reservation, self::fine(100_000));

        Event::assertDispatched(ReservationReleased::class, function (ReservationReleased $event) use ($reservation): bool {
            return $event->reservationEntryId === $reservation->value
                && $event->amount === 100_000
                && $event->partial === true
                && $event->stillReserved === 150_000;
        });
    }

    #[Test]
    public function rial_reservations_release_the_same_way(): void
    {
        $this->depositRial(self::ORG, 25_000_000_000);
        $reservation = $this->rialLedger()->reserve(self::ORG, self::money(19_654_437_500), LedgerReference::order(44120));

        // Worked example 1, g3: release the 5,007,500 surplus.
        $this->rialLedger()->release($reservation, self::money(5_007_500));

        $this->assertSame(19_649_430_000, $this->rialBalance(self::ORG, Bucket::RESERVED));
        $this->assertSame(5_350_570_000, $this->rialBalance(self::ORG));
    }
}
