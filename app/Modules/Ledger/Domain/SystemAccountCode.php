<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain;

/**
 * Internal accounts held under organization_id = 0
 * (docs/03-domain/03-ledger.md §3.3, seed data in docs/04-data/02-schema-mysql.md §2.7).
 *
 * Every quantity that enters or leaves the member population has to come from
 * or go to one of these, otherwise Σ(entries per asset) would stop being zero.
 */
enum SystemAccountCode: string
{
    case FEE_INCOME = 'FEE_INCOME';
    case ROUNDING_DIFFERENCE = 'ROUNDING_DIFFERENCE';
    case PROCESSING_LOSS = 'PROCESSING_LOSS';
    case ASSAY_VARIANCE = 'ASSAY_VARIANCE';
    case EXTERNAL_GOLD_IN = 'EXTERNAL_GOLD_IN';
    case EXTERNAL_GOLD_OUT = 'EXTERNAL_GOLD_OUT';
    case EXTERNAL_CASH_IN = 'EXTERNAL_CASH_IN';
    case EXTERNAL_CASH_OUT = 'EXTERNAL_CASH_OUT';
    case SUSPENSE = 'SUSPENSE';
    case CLEARING = 'CLEARING';

    /**
     * The seed rows of §2.7: [code, asset, metal, bucket, allows_negative].
     *
     * All system accounts allow negative balances — EXTERNAL_GOLD_IN goes more
     * negative with every deposit, which is exactly how the member population's
     * positive total is offset.
     *
     * @return array<int, array{code: self, asset: AssetType, metal: ?MetalType, bucket: Bucket}>
     */
    public static function seedDefinitions(): array
    {
        $rows = [
            [self::FEE_INCOME, AssetType::RIAL],
            [self::ROUNDING_DIFFERENCE, AssetType::RIAL],
            [self::ROUNDING_DIFFERENCE, AssetType::GOLD],
            [self::PROCESSING_LOSS, AssetType::GOLD],
            [self::ASSAY_VARIANCE, AssetType::GOLD],
            [self::EXTERNAL_GOLD_IN, AssetType::GOLD],
            [self::EXTERNAL_GOLD_OUT, AssetType::GOLD],
            [self::EXTERNAL_CASH_IN, AssetType::RIAL],
            [self::EXTERNAL_CASH_OUT, AssetType::RIAL],
            [self::SUSPENSE, AssetType::GOLD],
            [self::SUSPENSE, AssetType::RIAL],
            [self::CLEARING, AssetType::GOLD],
            [self::CLEARING, AssetType::RIAL],
        ];

        return array_map(
            static fn (array $row): array => [
                'code' => $row[0],
                'asset' => $row[1],
                'metal' => $row[1]->defaultMetalType(),
                'bucket' => Bucket::AVAILABLE,
            ],
            $rows,
        );
    }
}
