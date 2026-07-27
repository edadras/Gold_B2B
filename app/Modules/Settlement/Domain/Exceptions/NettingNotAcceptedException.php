<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * Invariant N6 and ADR-009: a batch executes only when every participant has
 * accepted. Netting is never automatic — silence is not consent.
 */
final class NettingNotAcceptedException extends DomainException
{
    /** @param list<int> $pendingOrganizationIds */
    public function __construct(
        public readonly int $batchId,
        public readonly array $pendingOrganizationIds,
    ) {
        parent::__construct(sprintf(
            'Batch %d still awaits %d participant(s)',
            $batchId,
            count($pendingOrganizationIds),
        ));
    }

    public function errorCode(): string
    {
        return 'NETTING_NOT_ACCEPTED';
    }

    public function userMessage(): string
    {
        return 'همه شرکت‌کنندگان هنوز تهاتر را نپذیرفته‌اند.';
    }

    public function details(): array
    {
        return [
            'batch_id' => $this->batchId,
            'pending_organization_ids' => $this->pendingOrganizationIds,
        ];
    }
}
