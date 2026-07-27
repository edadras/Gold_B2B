<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Contracts;

use App\Modules\Accounting\Domain\SourceType;
use InvalidArgumentException;

/**
 * Everything a posting rule might need, all of it optional and all of it scalar.
 *
 * Deliberately a bag of nullable ints rather than a typed trade object: the
 * facts arrive from domain events fired by modules this one must not import,
 * so whatever the event happens to carry is what we get. Each rule asserts the
 * fields it actually needs; the rest stay null.
 */
final readonly class PostingContext
{
    public function __construct(
        public int $organizationId,
        public int $sourceId,
        public string $entryDate,
        public SourceType $sourceType,
        public string $description = '',
        /** Fine gold moved by the event, in milligrams. */
        public int $fineMg = 0,
        /** Gross consideration in rial, before fees. */
        public int $grossRial = 0,
        /** Platform fee in rial. */
        public int $feeRial = 0,
        /** Cost of goods sold in rial — supplied by CostBasisService for a sale. */
        public int $cogsRial = 0,
        /** Any other rial amount the rule needs (penalty, refining cost, adjustment). */
        public int $amountRial = 0,
        public ?int $counterpartyOrgId = null,
        public ?int $createdByUserId = null,
    ) {
        if ($this->organizationId <= 0) {
            throw new InvalidArgumentException('PostingContext needs an organisation');
        }

        if ($this->sourceId <= 0) {
            throw new InvalidArgumentException('PostingContext needs a source id');
        }

        foreach ([
            'fineMg' => $this->fineMg,
            'grossRial' => $this->grossRial,
            'feeRial' => $this->feeRial,
            'cogsRial' => $this->cogsRial,
            'amountRial' => $this->amountRial,
        ] as $name => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException("PostingContext.{$name} must not be negative");
            }
        }
    }

    public function withCogs(int $cogsRial): self
    {
        return new self(
            organizationId: $this->organizationId,
            sourceId: $this->sourceId,
            entryDate: $this->entryDate,
            sourceType: $this->sourceType,
            description: $this->description,
            fineMg: $this->fineMg,
            grossRial: $this->grossRial,
            feeRial: $this->feeRial,
            cogsRial: $cogsRial,
            amountRial: $this->amountRial,
            counterpartyOrgId: $this->counterpartyOrgId,
            createdByUserId: $this->createdByUserId,
        );
    }
}
