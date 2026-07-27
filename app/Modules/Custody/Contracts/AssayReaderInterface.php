<?php

declare(strict_types=1);

namespace App\Modules\Custody\Contracts;

use App\Modules\Custody\Contracts\DTO\AssaySnapshot;

/**
 * Read access to assay certificates.
 *
 * Dispute needs the full history (a re-assay is the usual trigger for a purity
 * dispute); Trading only needs the current one.
 */
interface AssayReaderInterface
{
    public function find(int $assayId): ?AssaySnapshot;

    public function findByCode(string $assayCode): ?AssaySnapshot;

    public function findByQrToken(string $qrToken): ?AssaySnapshot;

    /** The VALID certificate for a lot, or null when it has never been assayed. */
    public function currentForLot(int $lotId): ?AssaySnapshot;

    /** @return list<AssaySnapshot> newest first, superseded certificates included */
    public function historyForLot(int $lotId): array;
}
