<?php

declare(strict_types=1);

namespace App\Modules\Custody\Tests\Feature;

use App\Modules\Custody\Application\Commands\SplitLotCommand;
use App\Modules\Custody\Application\Commands\SplitPart;
use App\Modules\Custody\Application\SplitService;
use App\Modules\Custody\Domain\Enums\LotStatus;
use App\Modules\Custody\Infrastructure\Models\GoldLotModel;
use App\Modules\Custody\Tests\Support\CustodyTestCase;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Weight;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Mass conservation under randomised input.
 *
 * Invariant 2 of docs/03-domain/02-gold-lot-assay.md §2.5:
 *
 *     Σ(children fine) + fine_loss == parent fine
 *
 * Because F1 floors, the loss can only ever be non-negative, and because it is
 * one sub-milligram remainder per child it can never exceed (children - 1) mg.
 * Anything else means the arithmetic has drifted and gold is being invented or
 * destroyed.
 */
#[Group('custody')]
#[Group('split')]
#[Group('slow')]
final class SplitConservationTest extends CustodyTestCase
{
    private const CASES = 500;

    #[Test]
    public function five_hundred_random_splits_conserve_fine_weight(): void
    {
        $service = app(SplitService::class);

        // Fixed seed: a failure is reproducible instead of a one-off mystery.
        mt_srand(20260104);

        $maxObservedLoss = 0;

        for ($case = 0; $case < self::CASES; $case++) {
            $grossMg = mt_rand(1_000, 5_000_000);
            $purityX10 = mt_rand(1, 10_000);
            $childCount = mt_rand(2, 6);

            $parent = $this->makeLot(grossMg: $grossMg, purityX10: $purityX10);
            $parentFine = (int) $parent->fine_weight_mg;

            if ($parentFine === 0) {
                // Purity so low that the whole lot rounds to zero fine weight;
                // nothing to conserve, and the CHECK on fine <= gross holds.
                continue;
            }

            $parts = $this->randomParts($grossMg, $childCount);

            $result = $service->split(new SplitLotCommand(
                parentLotId: (int) $parent->id,
                parts: $parts,
                requestedByUserId: 1,
            ));

            $context = sprintf(
                'case %d: gross=%d purity=%d children=%d',
                $case,
                $grossMg,
                $purityX10,
                $result->childCount(),
            );

            $this->assertSame(
                $parentFine,
                $result->totalChildFineMg() + $result->fineLossMg,
                "Σ children fine + loss != parent fine — {$context}",
            );

            $this->assertSame(
                $grossMg,
                $result->totalChildGrossMg() + $result->grossLossMg,
                "Σ children gross + loss != parent gross — {$context}",
            );

            $this->assertGreaterThanOrEqual(0, $result->fineLossMg, "negative loss mints gold — {$context}");

            $this->assertLessThanOrEqual(
                $result->childCount(),
                $result->fineLossMg,
                "loss larger than one milligram per child — {$context}",
            );

            $maxObservedLoss = max($maxObservedLoss, $result->fineLossMg);

            $this->assertSame(LotStatus::CONSUMED, $parent->fresh()->status, "parent not consumed — {$context}");
        }

        // Sanity: the test would be vacuous if rounding never actually bit.
        $this->assertGreaterThan(0, $maxObservedLoss, 'no rounding loss was ever observed — the test is not exercising F1');
    }

    #[Test]
    public function fine_denominated_splits_always_cover_the_promised_weight(): void
    {
        $service = app(SplitService::class);

        mt_srand(987654321);

        for ($case = 0; $case < 60; $case++) {
            $purityX10 = mt_rand(5_000, 10_000);
            $grossMg = mt_rand(100_000, 2_000_000);

            $parent = $this->makeLot(grossMg: $grossMg, purityX10: $purityX10);
            $parentFine = (int) $parent->fine_weight_mg;

            if ($parentFine < 10) {
                continue;
            }

            $requestedFine = intdiv($parentFine, 2);

            $result = $service->split(new SplitLotCommand(
                parentLotId: (int) $parent->id,
                parts: [SplitPart::byFine(FineWeight::fromMilligrams($requestedFine))],
                requestedByUserId: 1,
            ));

            $delivered = $result->childFineMg[0];

            $this->assertGreaterThanOrEqual(
                $requestedFine,
                $delivered,
                "F2 must round the gross weight UP so the child covers the promise (case {$case})",
            );

            $this->assertSame(
                $parentFine,
                $result->totalChildFineMg() + $result->fineLossMg,
                "conservation broke on a fine-denominated split (case {$case})",
            );
        }
    }

    /**
     * Partition the parent's gross weight into $count positive pieces, leaving
     * the remainder for the automatic remainder child.
     *
     * @return list<SplitPart>
     */
    private function randomParts(int $grossMg, int $count): array
    {
        $parts = [];
        $remaining = $grossMg;

        for ($i = 0; $i < $count - 1; $i++) {
            // Keep at least 1 mg per remaining child.
            $ceiling = $remaining - ($count - $i - 1);

            if ($ceiling < 1) {
                break;
            }

            $take = mt_rand(1, max(1, intdiv($ceiling, 2)));
            $parts[] = SplitPart::byGross(Weight::fromMilligrams($take));
            $remaining -= $take;
        }

        return $parts;
    }

    #[Test]
    public function a_split_never_changes_the_total_gold_on_the_books(): void
    {
        $service = app(SplitService::class);

        $parent = $this->makeLot(grossMg: 1_000_000, purityX10: 9_999);
        $before = (int) $parent->fine_weight_mg;

        $result = $service->split(new SplitLotCommand(
            parentLotId: (int) $parent->id,
            parts: [
                SplitPart::byGross(Weight::fromMilligrams(333_333)),
                SplitPart::byGross(Weight::fromMilligrams(333_333)),
            ],
            requestedByUserId: 1,
        ));

        $liveFine = (int) GoldLotModel::query()
            ->where('owner_organization_id', $this->defaultOwnerOrgId)
            ->where('status', '!=', LotStatus::CONSUMED->value)
            ->sum('fine_weight_mg');

        $this->assertSame(
            $before,
            $liveFine + $result->fineLossMg,
            'the owner holds exactly what they held before, minus the reported loss',
        );
    }
}
