<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Contracts;

use JsonSerializable;

/** One fee charged — §15.6 «کارمزدهای پرداختی». */
final readonly class FeeRow implements JsonSerializable
{
    public function __construct(
        public string $chargedOn,
        /** PLATFORM, BANK, REFINING, STORAGE, SHIPPING, PENALTY. */
        public string $category,
        public int $amountRial,
        public ?int $sourceId = null,
        public ?string $sourceType = null,
        public ?string $description = null,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'charged_on' => $this->chargedOn,
            'category' => $this->category,
            'amount_rial' => $this->amountRial,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'description' => $this->description,
        ];
    }
}
