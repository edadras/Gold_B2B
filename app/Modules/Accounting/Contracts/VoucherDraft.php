<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Contracts;

use App\Modules\Accounting\Domain\LineSet;
use App\Modules\Accounting\Domain\SourceType;
use App\Modules\Shared\Support\IntMath;
use InvalidArgumentException;

/**
 * An unposted voucher: what PostingRules produces and JournalPoster consumes.
 *
 * Immutable and free of Eloquent, so it can be built inside a listener, logged,
 * asserted on in a test, or handed across a module boundary.
 */
final readonly class VoucherDraft
{
    /** @param  array<int, VoucherLine>  $lines */
    public function __construct(
        public int $organizationId,
        public SourceType $sourceType,
        public int $sourceId,
        public string $entryDate,
        public string $description,
        public array $lines,
        public ?int $createdByUserId = null,
    ) {
        if ($this->organizationId <= 0) {
            throw new InvalidArgumentException('Voucher needs an organisation');
        }

        if ($this->sourceId <= 0) {
            throw new InvalidArgumentException('Voucher needs a source id for idempotency');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->entryDate) !== 1) {
            throw new InvalidArgumentException("Entry date must be Y-m-d, got: {$this->entryDate}");
        }

        if ($this->lines === []) {
            throw new InvalidArgumentException('Voucher must have at least one line');
        }

        foreach ($this->lines as $line) {
            if (! $line instanceof VoucherLine) {
                throw new InvalidArgumentException('Voucher lines must be VoucherLine instances');
            }
        }
    }

    /** @return array<int, VoucherLine> */
    public function linesIn(LineSet $set): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn (VoucherLine $line): bool => $line->set === $set,
        ));
    }

    public function totalDebitRial(): int
    {
        return IntMath::sum(array_map(static fn (VoucherLine $l): int => $l->debitRial, $this->lines));
    }

    public function totalCreditRial(): int
    {
        return IntMath::sum(array_map(static fn (VoucherLine $l): int => $l->creditRial, $this->lines));
    }

    public function totalDebitFineMg(): int
    {
        return IntMath::sum(array_map(static fn (VoucherLine $l): int => $l->debitFineMg, $this->lines));
    }

    public function totalCreditFineMg(): int
    {
        return IntMath::sum(array_map(static fn (VoucherLine $l): int => $l->creditFineMg, $this->lines));
    }

    public function isBalanced(): bool
    {
        return $this->totalDebitRial() === $this->totalCreditRial()
            && $this->totalDebitFineMg() === $this->totalCreditFineMg();
    }

    /** Drop lines that would post nothing — a zero fee should not create a row. */
    public function withoutEmptyLines(): self
    {
        return new self(
            organizationId: $this->organizationId,
            sourceType: $this->sourceType,
            sourceId: $this->sourceId,
            entryDate: $this->entryDate,
            description: $this->description,
            lines: array_values(array_filter(
                $this->lines,
                static fn (VoucherLine $line): bool => ! $line->isEmpty(),
            )),
            createdByUserId: $this->createdByUserId,
        );
    }

    public function withEntryDate(string $entryDate): self
    {
        return new self(
            organizationId: $this->organizationId,
            sourceType: $this->sourceType,
            sourceId: $this->sourceId,
            entryDate: $entryDate,
            description: $this->description,
            lines: $this->lines,
            createdByUserId: $this->createdByUserId,
        );
    }

    public function withSource(SourceType $sourceType, int $sourceId): self
    {
        return new self(
            organizationId: $this->organizationId,
            sourceType: $sourceType,
            sourceId: $sourceId,
            entryDate: $this->entryDate,
            description: $this->description,
            lines: $this->lines,
            createdByUserId: $this->createdByUserId,
        );
    }
}
