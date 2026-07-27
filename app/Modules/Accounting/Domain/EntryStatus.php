<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * docs/03-domain/09-accounting.md §9.2 — journal_entries.status.
 *
 * There is no DELETED: §9.7 «حذف — ممنوع؛ فقط REVERSED». A mistake is undone
 * by posting a mirror voucher that points back through `reverses_id`.
 */
enum EntryStatus: string
{
    case DRAFT = 'DRAFT';
    case POSTED = 'POSTED';
    case REVERSED = 'REVERSED';

    /** @return array<int, self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::DRAFT => [self::POSTED],
            self::POSTED => [self::REVERSED],
            self::REVERSED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** Only POSTED rows contribute to balances and the trial balance. */
    public function countsTowardsBalances(): bool
    {
        return $this === self::POSTED;
    }
}
