<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * Invariant N5 of docs/03-domain/05-settlement.md §5.6: no settlement may
 * belong to two netting batches at once, or its obligation would be
 * discharged twice.
 */
final class SettlementAlreadyNettedException extends DomainException
{
    public function __construct(
        public readonly int $settlementId,
        public readonly int $existingBatchId,
    ) {
        parent::__construct(sprintf(
            'Settlement %d already belongs to batch %d',
            $settlementId,
            $existingBatchId,
        ));
    }

    public function errorCode(): string
    {
        return 'SETTLEMENT_ALREADY_NETTED';
    }

    public function userMessage(): string
    {
        return 'این تسویه هم‌اکنون در یک دسته تهاتر دیگر است.';
    }

    public function details(): array
    {
        return [
            'settlement_id' => $this->settlementId,
            'existing_batch_id' => $this->existingBatchId,
        ];
    }
}
