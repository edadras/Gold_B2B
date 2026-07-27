<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Events;

/**
 * One participant refused, which kills the whole batch (§5.6: "حداقل یکی رد
 * کرد ► ابطال کل دسته ► تسویه ناخالص عادی").
 *
 * By the time this fires the batch is CANCELLED and every settlement in it has
 * fallen back from NETTING_QUEUE to PAYMENT_PENDING.
 *
 * @property list<int> $settlementIds
 */
final readonly class NettingRejected
{
    /** @param list<int> $settlementIds */
    public function __construct(
        public int $batchId,
        public string $batchCode,
        public int $organizationId,
        public ?int $rejectedByUserId,
        public string $reason,
        public array $settlementIds,
        public string $occurredAt,
    ) {}
}
