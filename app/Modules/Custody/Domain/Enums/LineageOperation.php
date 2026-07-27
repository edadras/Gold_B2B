<?php

declare(strict_types=1);

namespace App\Modules\Custody\Domain\Enums;

/** The operation that links a parent lot to a child lot. Schema §2.3. */
enum LineageOperation: string
{
    case SPLIT = 'SPLIT';
    case MERGE = 'MERGE';
    case MELT = 'MELT';
    case REASSAY = 'REASSAY';
}
