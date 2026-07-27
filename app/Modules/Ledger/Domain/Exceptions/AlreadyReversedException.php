<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * Invariant I8 — no entry may be reversed twice.
 *
 * Enforced by the UNIQUE key on ledger_reversals.original_entry_id as well;
 * this is the friendly path that fires before the database rejects it.
 */
final class AlreadyReversedException extends DomainException
{
    public function __construct(
        public readonly int $originalEntryId,
        public readonly ?int $existingReversalEntryId = null,
    ) {
        parent::__construct("Ledger entry {$originalEntryId} has already been reversed");
    }

    public function errorCode(): string
    {
        return 'ALREADY_REVERSED';
    }

    public function userMessage(): string
    {
        return 'این ثبت قبلاً معکوس شده است.';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return [
            'originalEntryId' => $this->originalEntryId,
            'reversalEntryId' => $this->existingReversalEntryId,
        ];
    }
}
