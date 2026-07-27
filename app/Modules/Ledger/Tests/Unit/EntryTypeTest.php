<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Tests\Unit;

use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\EntryType;
use App\Modules\Ledger\Domain\SystemAccountCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Guards the enum against the §3.4 table in docs/03-domain/03-ledger.md.
 *
 * If someone deletes a case the doc requires, or renames one, this fails. The
 * expectations below are transcribed from that table, not from the enum.
 */
final class EntryTypeTest extends TestCase
{
    /**
     * The §3.4 table verbatim: value => [assets, sign] where sign is
     * '+', '-' or '±' and assets is 'GOLD', 'RIAL' or 'both'.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private const DOC_TABLE = [
        'OPENING_BALANCE' => ['both', '+'],
        'DEPOSIT_GOLD' => ['GOLD', '+'],
        'WITHDRAW_GOLD' => ['GOLD', '-'],
        'DEPOSIT_CASH' => ['RIAL', '+'],
        'WITHDRAW_CASH' => ['RIAL', '-'],
        'TRADE_BUY_GOLD' => ['GOLD', '+'],
        'TRADE_SELL_GOLD' => ['GOLD', '-'],
        'TRADE_BUY_CASH' => ['RIAL', '-'],
        'TRADE_SELL_CASH' => ['RIAL', '+'],
        'RESERVE' => ['both', '±'],
        'RELEASE' => ['both', '±'],
        'FEE_CHARGE' => ['RIAL', '-'],
        'FEE_INCOME' => ['RIAL', '+'],
        'TAX_WITHHOLD' => ['RIAL', '-'],
        'ASSAY_ADJUSTMENT' => ['GOLD', '±'],
        'PROCESSING_LOSS' => ['GOLD', '-'],
        'ROUNDING' => ['both', '±'],
        'NETTING_SETTLE' => ['both', '±'],
        'DISPUTE_HOLD' => ['both', '±'],
        'DISPUTE_RELEASE' => ['both', '±'],
        'PENALTY' => ['RIAL', '-'],
        'REVERSAL' => ['both', '±'],
        'MANUAL_ADJUSTMENT' => ['both', '±'],
    ];

    #[Test]
    public function every_entry_type_in_the_documentation_exists(): void
    {
        foreach (array_keys(self::DOC_TABLE) as $value) {
            $this->assertInstanceOf(
                EntryType::class,
                EntryType::tryFrom($value),
                "docs §3.4 lists entry type {$value} but the enum has no case for it",
            );
        }
    }

    #[Test]
    public function the_canonical_set_is_exactly_the_documented_table(): void
    {
        $this->assertSame(
            array_keys(self::DOC_TABLE),
            array_map(static fn (EntryType $t): string => $t->value, EntryType::canonical()),
        );
    }

    #[Test]
    public function extra_cases_are_flagged_as_non_canonical(): void
    {
        foreach (EntryType::cases() as $case) {
            $this->assertSame(
                array_key_exists($case->value, self::DOC_TABLE),
                $case->isCanonical(),
                "isCanonical() disagrees with docs §3.4 for {$case->value}",
            );
        }

        // The lifecycle types the worked examples use but §3.4 does not list.
        $this->assertNotEmpty(array_filter(
            EntryType::cases(),
            static fn (EntryType $t): bool => ! $t->isCanonical(),
        ));
    }

    #[Test]
    #[DataProvider('documentedTypes')]
    public function each_type_applies_to_the_documented_assets(string $value, string $assets): void
    {
        $type = EntryType::from($value);

        $expected = match ($assets) {
            'GOLD' => [AssetType::GOLD],
            'RIAL' => [AssetType::RIAL],
            default => [AssetType::GOLD, AssetType::RIAL],
        };

        $this->assertSame($expected, $type->assets(), "Asset applicability wrong for {$value}");

        foreach ($expected as $asset) {
            $this->assertTrue($type->appliesTo($asset));
        }
    }

    #[Test]
    #[DataProvider('documentedTypes')]
    public function each_type_carries_the_documented_sign_constraint(string $value, string $assets, string $sign): void
    {
        $type = EntryType::from($value);

        $expected = match ($sign) {
            '+' => 1,
            '-' => -1,
            default => null,
        };

        // RESERVE / RELEASE / DISPUTE_* post a balanced pair, so both signs are
        // legal even though §3.4 describes only the AVAILABLE leg.
        $pairTypes = ['RESERVE', 'RELEASE', 'DISPUTE_HOLD', 'DISPUTE_RELEASE'];

        if (in_array($value, $pairTypes, true)) {
            $this->assertNull($type->requiredSign(), "{$value} posts a pair and must accept both signs");

            return;
        }

        $this->assertSame($expected, $type->requiredSign(), "Sign constraint wrong for {$value}");
    }

    #[Test]
    public function correcting_types_require_two_people(): void
    {
        $this->assertTrue(EntryType::MANUAL_ADJUSTMENT->requiresDualApproval());
        $this->assertTrue(EntryType::REVERSAL->requiresDualApproval());
        $this->assertFalse(EntryType::RESERVE->requiresDualApproval());
        $this->assertFalse(EntryType::TRADE_BUY_GOLD->requiresDualApproval());
    }

    #[Test]
    public function every_type_has_a_system_counterpart_for_both_signs(): void
    {
        foreach (EntryType::cases() as $type) {
            foreach ($type->assets() as $asset) {
                foreach ([1, -1] as $sign) {
                    $this->assertInstanceOf(
                        SystemAccountCode::class,
                        $type->systemCounterpart($asset, $sign),
                        "No system counterpart for {$type->value} ({$asset->value}, {$sign})",
                    );
                }
            }
        }
    }

    #[Test]
    public function fees_and_rounding_route_to_their_named_system_accounts(): void
    {
        $this->assertSame(
            SystemAccountCode::FEE_INCOME,
            EntryType::FEE_CHARGE->systemCounterpart(AssetType::RIAL, -1),
        );
        $this->assertSame(
            SystemAccountCode::ROUNDING_DIFFERENCE,
            EntryType::ROUNDING->systemCounterpart(AssetType::GOLD, -1),
        );
        $this->assertSame(
            SystemAccountCode::EXTERNAL_GOLD_IN,
            EntryType::DEPOSIT_GOLD->systemCounterpart(AssetType::GOLD, 1),
        );
        $this->assertSame(
            SystemAccountCode::EXTERNAL_GOLD_OUT,
            EntryType::WITHDRAW_GOLD->systemCounterpart(AssetType::GOLD, -1),
        );
    }

    #[Test]
    public function every_system_account_named_in_the_docs_exists(): void
    {
        // docs/03-domain/03-ledger.md §3.3
        foreach ([
            'FEE_INCOME', 'ROUNDING_DIFFERENCE', 'PROCESSING_LOSS', 'ASSAY_VARIANCE',
            'EXTERNAL_GOLD_IN', 'EXTERNAL_GOLD_OUT', 'EXTERNAL_CASH_IN', 'EXTERNAL_CASH_OUT',
            'SUSPENSE', 'CLEARING',
        ] as $code) {
            $this->assertInstanceOf(SystemAccountCode::class, SystemAccountCode::tryFrom($code));
        }

        $this->assertCount(10, SystemAccountCode::cases());
        $this->assertCount(13, SystemAccountCode::seedDefinitions(), 'docs §2.7 seeds 13 system accounts');
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function documentedTypes(): array
    {
        $out = [];

        foreach (self::DOC_TABLE as $value => [$assets, $sign]) {
            $out[$value] = [$value, $assets, $sign];
        }

        return $out;
    }
}
