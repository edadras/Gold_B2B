<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Tests;

use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use PHPUnit\Framework\Attributes\Test;

/** §10.7 — the white list, the black list, and their one-sidedness. */
final class RelationFlagsTest extends CounterpartyTestCase
{
    #[Test]
    public function blocking_is_one_sided_and_invisible_to_the_blocked_side(): void
    {
        $this->relations->setFlags(101, 202, isBlocked: true);

        self::assertTrue($this->relations->isBlocked(101, 202));
        // 202's own view of 101 is untouched — nothing in its row changed, so
        // nothing in its UI can hint that it was blocked.
        self::assertFalse($this->relations->isBlocked(202, 101));
        self::assertFalse($this->relations->isTrusted(202, 101));
    }

    #[Test]
    public function the_matching_filter_gets_the_full_block_list(): void
    {
        $this->relations->setFlags(101, 202, isBlocked: true);
        $this->relations->setFlags(101, 203, isBlocked: true);
        $this->relations->setFlags(101, 204, isTrusted: true);

        $blocked = $this->relations->blockedCounterparties(101);
        sort($blocked);

        self::assertSame([202, 203], $blocked);
    }

    #[Test]
    public function trusted_counterparties_can_auto_accept_otc(): void
    {
        $this->relations->setFlags(101, 202, isTrusted: true, autoAcceptOtc: true);

        self::assertTrue($this->relations->isTrusted(101, 202));
        self::assertTrue($this->relations->autoAcceptsOtc(101, 202));
    }

    #[Test]
    public function blocking_withdraws_auto_accept(): void
    {
        $this->relations->setFlags(101, 202, isTrusted: true, autoAcceptOtc: true);
        $this->relations->setFlags(101, 202, isTrusted: false, isBlocked: true);

        self::assertFalse($this->relations->autoAcceptsOtc(101, 202));
        self::assertFalse($this->relations->snapshot(101, 202)?->autoAcceptOtc);
    }

    #[Test]
    public function a_counterparty_cannot_be_trusted_and_blocked_at_once(): void
    {
        $this->relations->setFlags(101, 202, isTrusted: true);

        $this->expectException(OperationNotPermittedException::class);

        $this->relations->setFlags(101, 202, isBlocked: true);
    }

    #[Test]
    public function flags_survive_trading_and_appear_on_the_snapshot(): void
    {
        $this->relations->setFlags(101, 202, isTrusted: true, internalNote: 'قدیمی‌ترین مشتری');
        $this->relations->applyTrade(101, 202, 250_000, -19_522_500_000, reference: 'TRD-1');

        $snapshot = $this->relations->snapshot(101, 202);

        self::assertNotNull($snapshot);
        self::assertTrue($snapshot->isTrusted);
        self::assertSame(250_000, $snapshot->goldBalanceMg);
        self::assertTrue($snapshot->isGoldReceivable());
        self::assertFalse($snapshot->isRialReceivable());
        self::assertSame(1, $snapshot->totalTradeCount);
        // The private annotation is never part of the shared read model.
        self::assertArrayNotHasKey('internal_note', $snapshot->toArray());
    }

    #[Test]
    public function trusted_members_sort_to_the_top_of_the_list(): void
    {
        $this->relations->applyTrade(101, 202, 900_000, 0);
        $this->relations->applyTrade(101, 203, 100_000, 0);
        $this->relations->setFlags(101, 203, isTrusted: true);

        $list = $this->relations->listFor(101);

        self::assertSame(203, $list[0]->counterpartyOrgId, 'trusted first, then by volume');
        self::assertSame(202, $list[1]->counterpartyOrgId);
    }
}
