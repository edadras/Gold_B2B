<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * A transfer whose source and destination are the same organisation.
 *
 * Always a caller bug: value that stays inside one organisation moves between
 * buckets (moveBucket), it does not "transfer".
 */
final class SelfTransferException extends DomainException
{
    public function __construct(public readonly int $organizationId)
    {
        parent::__construct("Cannot transfer to the same organization ({$organizationId})");
    }

    public function errorCode(): string
    {
        return 'SELF_TRANSFER';
    }

    public function userMessage(): string
    {
        return 'انتقال به خودِ سازمان امکان‌پذیر نیست.';
    }

    public function details(): array
    {
        return ['organizationId' => $this->organizationId];
    }
}
