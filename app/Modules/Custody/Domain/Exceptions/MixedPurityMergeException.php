<?php

declare(strict_types=1);

namespace App\Modules\Custody\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * A logical merge cannot average purities — that would invent metal.
 * docs/03-domain/02-gold-lot-assay.md §2.6: mixed purity requires a real MELT
 * followed by a fresh assay.
 */
final class MixedPurityMergeException extends DomainException
{
    /**
     * @param  list<int>  $lotIds
     * @param  list<int>  $distinctPurities
     */
    public function __construct(
        public readonly array $lotIds,
        public readonly array $distinctPurities,
    ) {
        parent::__construct(sprintf(
            'Cannot logically merge lots with different purities (%s). Use a MELT operation instead; the output must be re-assayed.',
            implode(', ', $distinctPurities),
        ));
    }

    public function errorCode(): string
    {
        return 'MIXED_PURITY_MERGE';
    }

    public function userMessage(): string
    {
        return 'ادغام قطعات با عیارهای متفاوت بدون ذوب ممکن نیست. برای این کار باید عملیات ذوب ثبت شود و خروجی مجدداً ری‌گیری گردد.';
    }

    public function details(): array
    {
        return [
            'lot_ids' => $this->lotIds,
            'distinct_purities' => $this->distinctPurities,
            'remedy' => 'MELT',
        ];
    }
}
