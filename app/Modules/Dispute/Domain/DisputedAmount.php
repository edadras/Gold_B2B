<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Domain;

use JsonSerializable;

/**
 * What is actually in dispute — and therefore what gets locked.
 *
 * §13.4 is emphatic: «مبلغ قفل‌شده = فقط مبلغ مورد ادعا، نه کل معامله». A
 * 39-billion-rial trade with a 394-million-rial purity claim locks 394 million.
 * Freezing the whole trade would punish the respondent for being accused, and
 * would make opening a dispute a weapon.
 */
final readonly class DisputedAmount implements JsonSerializable
{
    public function __construct(
        public int $fineMg,
        public int $rial,
        /** How the figure was arrived at, for the timeline entry. */
        public string $basis = '',
    ) {}

    public static function none(): self
    {
        return new self(0, 0, 'no quantified claim');
    }

    public static function ofRial(int $rial, string $basis = ''): self
    {
        return new self(0, $rial, $basis);
    }

    public static function ofGold(int $fineMg, int $rial, string $basis = ''): self
    {
        return new self($fineMg, $rial, $basis);
    }

    public function isEmpty(): bool
    {
        return $this->fineMg === 0 && $this->rial === 0;
    }

    public function hasGold(): bool
    {
        return $this->fineMg > 0;
    }

    public function hasRial(): bool
    {
        return $this->rial > 0;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'fine_mg' => $this->fineMg,
            'rial' => $this->rial,
            'basis' => $this->basis,
        ];
    }
}
