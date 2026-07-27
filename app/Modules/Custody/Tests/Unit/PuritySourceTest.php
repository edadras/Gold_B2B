<?php

declare(strict_types=1);

namespace App\Modules\Custody\Tests\Unit;

use App\Modules\Custody\Domain\Enums\AccreditationLevel;
use App\Modules\Custody\Domain\Enums\PuritySource;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** docs/03-domain/02-gold-lot-assay.md §2.4 and §2.9. */
#[Group('custody')]
final class PuritySourceTest extends TestCase
{
    #[Test]
    public function only_assayed_gold_reaches_the_order_book(): void
    {
        $this->assertTrue(PuritySource::ASSAYED->isTradableOnOrderBook());
        $this->assertFalse(PuritySource::DECLARED->isTradableOnOrderBook());
        $this->assertFalse(PuritySource::ESTIMATED->isTradableOnOrderBook());
    }

    #[Test]
    public function declared_gold_is_otc_only_and_warns_the_buyer(): void
    {
        $this->assertTrue(PuritySource::DECLARED->isTradableOtc());
        $this->assertTrue(PuritySource::DECLARED->requiresBuyerWarning());
        $this->assertFalse(PuritySource::ESTIMATED->isTradableOtc());
    }

    #[Test]
    public function only_assayed_gold_can_be_collateral(): void
    {
        $this->assertTrue(PuritySource::ASSAYED->isEligibleAsCollateral());
        $this->assertFalse(PuritySource::DECLARED->isEligibleAsCollateral());
        $this->assertFalse(PuritySource::ESTIMATED->isEligibleAsCollateral());
    }

    #[Test]
    public function a_merge_inherits_the_weakest_source(): void
    {
        $this->assertSame(PuritySource::DECLARED, PuritySource::ASSAYED->lowerOf(PuritySource::DECLARED));
        $this->assertSame(PuritySource::ESTIMATED, PuritySource::DECLARED->lowerOf(PuritySource::ESTIMATED));
        $this->assertSame(PuritySource::ASSAYED, PuritySource::ASSAYED->lowerOf(PuritySource::ASSAYED));
    }

    #[Test]
    public function an_unaccredited_lab_can_only_produce_declared_purity(): void
    {
        $this->assertSame(PuritySource::ASSAYED, AccreditationLevel::TIER_1->grantedPuritySource());
        $this->assertSame(PuritySource::ASSAYED, AccreditationLevel::TIER_2->grantedPuritySource());
        $this->assertSame(PuritySource::DECLARED, AccreditationLevel::UNACCREDITED->grantedPuritySource());
    }
}
