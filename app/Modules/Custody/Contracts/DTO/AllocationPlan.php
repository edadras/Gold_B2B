<?php

declare(strict_types=1);

namespace App\Modules\Custody\Contracts\DTO;

use App\Modules\Shared\ValueObjects\FineWeight;

/**
 * The result of LotAllocator::allocate — which lots satisfy a requirement,
 * which are consumed whole and which single lot needs splitting.
 *
 * A plan is a proposal, not a reservation: the caller still has to lock and
 * act on the lots inside its own transaction.
 */
final readonly class AllocationPlan
{
    /** @param list<AllocationItem> $items */
    public function __construct(
        public int $ownerOrganizationId,
        public int $requiredFineMg,
        public array $items,
        public int $allocatedFineMg,
        public ?int $minPurityX10 = null,
    ) {}

    /** @return list<AllocationItem> */
    public function wholeItems(): array
    {
        return array_values(array_filter($this->items, static fn (AllocationItem $i): bool => $i->whole));
    }

    /** @return list<int> */
    public function wholeLotIds(): array
    {
        return array_map(static fn (AllocationItem $i): int => $i->lotId, $this->wholeItems());
    }

    /** The one lot (if any) that must be split to hit the required weight exactly. */
    public function splitItem(): ?AllocationItem
    {
        foreach ($this->items as $item) {
            if (! $item->whole) {
                return $item;
            }
        }

        return null;
    }

    public function requiresSplit(): bool
    {
        return $this->splitItem() !== null;
    }

    public function splitCount(): int
    {
        return $this->requiresSplit() ? 1 : 0;
    }

    /** @return list<int> every lot touched by the plan, in ascending id order */
    public function lotIds(): array
    {
        $ids = array_map(static fn (AllocationItem $i): int => $i->lotId, $this->items);
        sort($ids);

        return array_values($ids);
    }

    public function required(): FineWeight
    {
        return FineWeight::fromMilligrams($this->requiredFineMg);
    }

    public function isExactMatch(): bool
    {
        return count($this->items) === 1
            && $this->items[0]->whole
            && $this->items[0]->lotFineMg === $this->requiredFineMg;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'owner_organization_id' => $this->ownerOrganizationId,
            'required_fine_mg' => $this->requiredFineMg,
            'allocated_fine_mg' => $this->allocatedFineMg,
            'min_purity_x10' => $this->minPurityX10,
            'requires_split' => $this->requiresSplit(),
            'items' => array_map(static fn (AllocationItem $i): array => $i->toArray(), $this->items),
        ];
    }
}
