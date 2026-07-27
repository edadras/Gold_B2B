<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

final class LedgerEntryNotFoundException extends DomainException
{
    public function __construct(public readonly int $entryId)
    {
        parent::__construct("Ledger entry {$entryId} does not exist");
    }

    public function errorCode(): string
    {
        return 'LEDGER_ENTRY_NOT_FOUND';
    }

    public function userMessage(): string
    {
        return 'ثبت دفتر کل یافت نشد.';
    }

    public function httpStatus(): int
    {
        return 404;
    }

    public function details(): array
    {
        return ['entryId' => $this->entryId];
    }
}
