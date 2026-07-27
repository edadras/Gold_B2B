<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * Invariant N1 of docs/03-domain/05-settlement.md §5.6:
 * Σ(net_position) over all participants must be exactly zero.
 *
 * A non-zero sum means value would be created or destroyed by the batch, so the
 * batch is refused before a single ledger row is written.
 */
final class NettingImbalanceException extends DomainException
{
    public function __construct(public readonly int $sum, public readonly string $assetType)
    {
        parent::__construct(sprintf('Net positions sum to %d, expected 0', $sum));
    }

    public function errorCode(): string
    {
        return 'NETTING_IMBALANCE';
    }

    public function userMessage(): string
    {
        return 'موقعیت‌های خالص تهاتر تراز نیستند.';
    }

    public function details(): array
    {
        return ['sum' => $this->sum, 'asset_type' => $this->assetType];
    }
}
