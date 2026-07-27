<?php

declare(strict_types=1);

namespace App\Modules\Shared\Exceptions;

final class UnbalancedTransactionException extends DomainException
{
    public function __construct(
        public readonly string $transactionGroup,
        public readonly string $assetType,
        public readonly int $sum,
    ) {
        parent::__construct('UNBALANCED_TRANSACTION');
    }

    public function errorCode(): string
    {
        return 'UNBALANCED_TRANSACTION';
    }

    public function userMessage(): string
    {
        return 'خطای داخلی در تراز ثبت‌های مالی.';
    }

    public function httpStatus(): int
    {
        return 500;
    }

    public function details(): array
    {
        return [
            'transactionGroup' => $this->transactionGroup,
            'assetType' => $this->assetType,
            'sum' => $this->sum,
        ];
    }
}
