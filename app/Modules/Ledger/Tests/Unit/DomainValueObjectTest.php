<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Tests\Unit;

use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\Direction;
use App\Modules\Ledger\Domain\LedgerEntryId;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Ledger\Domain\TransactionGroup;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Rial;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DomainValueObjectTest extends TestCase
{
    #[Test]
    public function transaction_groups_are_uuids(): void
    {
        $group = TransactionGroup::generate();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $group->value,
        );
        $this->assertTrue($group->equals(TransactionGroup::fromString($group->value)));
        $this->assertFalse($group->equals(TransactionGroup::generate()));
    }

    #[Test]
    public function a_transaction_group_rejects_anything_that_is_not_a_uuid(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TransactionGroup::fromString('g99');
    }

    #[Test]
    public function references_have_a_named_constructor_per_source_document(): void
    {
        $this->assertSame('order:44101', LedgerReference::order(44101)->key());
        $this->assertSame('trade:88231', LedgerReference::trade(88231)->key());
        $this->assertSame('settlement:88231', LedgerReference::settlement(88231)->key());
        $this->assertSame('dispute:12', LedgerReference::dispute(12)->key());
        $this->assertSame('custody:1601', LedgerReference::custody(1601)->key());
        $this->assertSame('adjustment:7', LedgerReference::adjustment(7)->key());
    }

    #[Test]
    public function references_compare_by_value(): void
    {
        $this->assertTrue(LedgerReference::trade(1)->equals(LedgerReference::trade(1)));
        $this->assertFalse(LedgerReference::trade(1)->equals(LedgerReference::order(1)));
        $this->assertFalse(LedgerReference::trade(1)->equals(LedgerReference::trade(2)));
    }

    #[Test]
    public function a_reference_type_must_fit_the_column(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LedgerReference::of(str_repeat('x', 51), 1);
    }

    #[Test]
    public function entry_ids_must_be_positive(): void
    {
        $this->assertSame(5, LedgerEntryId::fromInt(5)->value);

        $this->expectException(InvalidArgumentException::class);
        LedgerEntryId::fromInt(0);
    }

    #[Test]
    public function direction_follows_the_sign(): void
    {
        $this->assertSame(Direction::CREDIT, Direction::ofAmount(1));
        $this->assertSame(Direction::DEBIT, Direction::ofAmount(-1));
    }

    #[Test]
    public function a_zero_amount_has_no_direction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Direction::ofAmount(0);
    }

    #[Test]
    public function asset_types_unwrap_only_their_own_value_object(): void
    {
        $this->assertSame(250_000, AssetType::GOLD->unwrap(FineWeight::fromMilligrams(250_000)));
        $this->assertSame(-500, AssetType::RIAL->unwrap(Rial::fromRial(-500)));

        $this->expectException(InvalidArgumentException::class);
        AssetType::GOLD->unwrap(Rial::fromRial(100));
    }

    #[Test]
    public function the_rial_ledger_rejects_a_weight(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AssetType::RIAL->unwrap(FineWeight::fromMilligrams(100));
    }

    #[Test]
    public function payable_is_the_only_bucket_allowed_to_go_negative(): void
    {
        foreach (Bucket::cases() as $bucket) {
            $this->assertSame(
                $bucket === Bucket::PAYABLE,
                $bucket->allowsNegative(),
                "Invariant I3 disagrees for {$bucket->value}",
            );
        }
    }

    #[Test]
    public function payable_exists_only_for_rial(): void
    {
        $this->assertNotContains(Bucket::PAYABLE, Bucket::forAsset(AssetType::GOLD));
        $this->assertContains(Bucket::PAYABLE, Bucket::forAsset(AssetType::RIAL));
        $this->assertCount(4, Bucket::forAsset(AssetType::GOLD));
        $this->assertCount(5, Bucket::forAsset(AssetType::RIAL));
    }
}
