<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Contracts;

use JsonSerializable;

/**
 * Outcome of JournalPoster::post().
 *
 * `alreadyExisted` is how a caller tells a first delivery from a redelivery:
 * both return the same voucher, only one of them created it.
 */
final readonly class PostingResult implements JsonSerializable
{
    public function __construct(
        public int $journalEntryId,
        public string $voucherNo,
        public bool $alreadyExisted,
        public int $lineCount,
    ) {}

    public function wasCreated(): bool
    {
        return ! $this->alreadyExisted;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'journal_entry_id' => $this->journalEntryId,
            'voucher_no' => $this->voucherNo,
            'already_existed' => $this->alreadyExisted,
            'line_count' => $this->lineCount,
        ];
    }
}
