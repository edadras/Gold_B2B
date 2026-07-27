<?php

declare(strict_types=1);

namespace App\Modules\Custody\Domain\Enums;

/** How a lot came into existence — docs/03-domain/02-gold-lot-assay.md §2.2. */
enum OriginType: string
{
    case MELT = 'MELT';
    case IMPORT = 'IMPORT';
    case MEMBER_DEPOSIT = 'MEMBER_DEPOSIT';
    case SPLIT = 'SPLIT';
    case MERGE = 'MERGE';
    case REASSAY = 'REASSAY';

    /** Origins that derive from one or more existing lots and need lineage rows. */
    public function hasParents(): bool
    {
        return match ($this) {
            self::SPLIT, self::MERGE, self::MELT, self::REASSAY => true,
            self::IMPORT, self::MEMBER_DEPOSIT => false,
        };
    }

    public function toLineageOperation(): ?LineageOperation
    {
        return match ($this) {
            self::SPLIT => LineageOperation::SPLIT,
            self::MERGE => LineageOperation::MERGE,
            self::MELT => LineageOperation::MELT,
            self::REASSAY => LineageOperation::REASSAY,
            default => null,
        };
    }
}
