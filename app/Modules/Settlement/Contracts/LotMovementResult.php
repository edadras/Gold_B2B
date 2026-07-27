<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Contracts;

/**
 * What a delivery did to the lots, as observed by Settlement.
 *
 * physicalMovement is the field that matters: pattern 4 of
 * docs/03-domain/05-settlement.md §5.3 delivers gold without moving any, and
 * this is the flag that proves it.
 */
final readonly class LotMovementResult
{
    /**
     * @param  list<int>  $deliveredLotIds  lots now owned by the receiver
     * @param  list<int>  $retainedLotIds  split remainders left with the deliverer
     */
    public function __construct(
        public array $deliveredLotIds,
        public array $retainedLotIds = [],
        public bool $splitPerformed = false,
        public bool $physicalMovement = false,
        public ?string $custodianTypeBefore = null,
        public ?int $custodianIdBefore = null,
        public ?string $locationBefore = null,
        public ?string $custodianTypeAfter = null,
        public ?int $custodianIdAfter = null,
        public ?string $locationAfter = null,
    ) {}

    public static function none(): self
    {
        return new self([], []);
    }

    public function lotCount(): int
    {
        return count($this->deliveredLotIds);
    }

    /** Custody stayed exactly where it was — the §5.3 pattern 4 assertion. */
    public function custodyUnchanged(): bool
    {
        return ! $this->physicalMovement
            && $this->custodianTypeBefore === $this->custodianTypeAfter
            && $this->custodianIdBefore === $this->custodianIdAfter
            && $this->locationBefore === $this->locationAfter;
    }
}
