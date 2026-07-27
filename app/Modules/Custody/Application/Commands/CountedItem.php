<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application\Commands;

use App\Modules\Custody\Domain\Exceptions\InvalidLotOperationException;
use App\Modules\Shared\ValueObjects\Weight;

/**
 * One line read off the vault officer's scanner during a stock count —
 * docs/03-domain/06-custody-vault.md §6.6 steps 2-3.
 *
 * Identified by lot code (the QR scan) or, when the QR is unreadable, by the
 * serial number engraved on the piece.
 */
final readonly class CountedItem
{
    public function __construct(
        public ?string $lotCode,
        public ?string $serialNumber,
        public int $countedGrossMg,
        public ?string $locationCode = null,
    ) {
        if ($lotCode === null && $serialNumber === null) {
            throw new InvalidLotOperationException('AUDIT_COUNT', 'a counted piece needs a lot code or a serial number');
        }

        if ($countedGrossMg < 0) {
            throw new InvalidLotOperationException('AUDIT_COUNT', 'counted weight cannot be negative');
        }
    }

    public static function fromScan(string $lotCode, Weight $counted, ?string $locationCode = null): self
    {
        return new self($lotCode, null, $counted->milligrams, $locationCode);
    }

    public static function fromSerial(string $serialNumber, Weight $counted, ?string $locationCode = null): self
    {
        return new self(null, $serialNumber, $counted->milligrams, $locationCode);
    }

    public function key(): string
    {
        return $this->lotCode ?? 'SN:'.$this->serialNumber;
    }
}
