<?php

declare(strict_types=1);

namespace App\Modules\Custody\Contracts\DTO;

use App\Modules\Shared\ValueObjects\FineWeight;

/**
 * What a delivery did to the lots — the return value of
 * Custody\Contracts\LotDeliveryInterface.
 *
 * The before/after custodian and location fields are the point of the DTO. A
 * change of owner is a book entry, not a physical operation (ADR-005,
 * docs/03-domain/06-custody-vault.md §6.2), so a caller can compare the two
 * halves and prove no metal moved. custodyUnchanged() is that comparison.
 */
final readonly class LotDeliveryResult
{
    /**
     * @param  list<int>  $deliveredLotIds  lots now owned by the receiver
     * @param  list<int>  $createdLotIds  every lot the split brought into existence
     * @param  ?int  $remainderLotId  the child left with the sender, if the plan cut a lot
     * @param  int  $deliveredFineMg  Σ fine weight actually handed over
     */
    public function __construct(
        public array $deliveredLotIds,
        public array $createdLotIds = [],
        public ?int $remainderLotId = null,
        public int $deliveredFineMg = 0,
        public bool $splitPerformed = false,
        public ?string $custodianTypeBefore = null,
        public ?int $custodianIdBefore = null,
        public ?string $locationBefore = null,
        public ?string $custodianTypeAfter = null,
        public ?int $custodianIdAfter = null,
        public ?string $locationAfter = null,
    ) {}

    public static function none(): self
    {
        return new self(deliveredLotIds: []);
    }

    public function lotCount(): int
    {
        return count($this->deliveredLotIds);
    }

    /** @return list<int> the remainder, as a list, for callers that think in sets */
    public function retainedLotIds(): array
    {
        return $this->remainderLotId === null ? [] : [$this->remainderLotId];
    }

    public function deliveredFine(): FineWeight
    {
        return FineWeight::fromMilligrams($this->deliveredFineMg);
    }

    /**
     * Custodian and physical location are byte-for-byte what they were. The
     * assertion ADR-005 exists to make.
     */
    public function custodyUnchanged(): bool
    {
        return $this->custodianTypeBefore === $this->custodianTypeAfter
            && $this->custodianIdBefore === $this->custodianIdAfter
            && $this->locationBefore === $this->locationAfter;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'delivered_lot_ids' => $this->deliveredLotIds,
            'created_lot_ids' => $this->createdLotIds,
            'remainder_lot_id' => $this->remainderLotId,
            'delivered_fine_mg' => $this->deliveredFineMg,
            'split_performed' => $this->splitPerformed,
            'custodian_type_before' => $this->custodianTypeBefore,
            'custodian_id_before' => $this->custodianIdBefore,
            'location_before' => $this->locationBefore,
            'custodian_type_after' => $this->custodianTypeAfter,
            'custodian_id_after' => $this->custodianIdAfter,
            'location_after' => $this->locationAfter,
            'custody_unchanged' => $this->custodyUnchanged(),
        ];
    }
}
