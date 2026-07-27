<?php

declare(strict_types=1);

namespace App\Modules\Shared\Exceptions;

final class InsufficientBalanceException extends DomainException
{
    public function __construct(
        public readonly int $required,
        public readonly int $available,
        public readonly string $asset = 'GOLD',
        public readonly ?int $accountId = null,
    ) {
        parent::__construct("Insufficient {$asset} balance: required {$required}, available {$available}");
    }

    public function errorCode(): string
    {
        return $this->asset === 'GOLD' ? 'INSUFFICIENT_GOLD' : 'INSUFFICIENT_RIAL';
    }

    public function userMessage(): string
    {
        return $this->asset === 'GOLD'
            ? 'موجودی طلای شما برای این عملیات کافی نیست.'
            : 'موجودی ریالی شما برای این عملیات کافی نیست.';
    }

    public function details(): array
    {
        return [
            'asset' => $this->asset,
            'required' => $this->required,
            'available' => $this->available,
            'shortfall' => $this->required - $this->available,
        ];
    }
}
