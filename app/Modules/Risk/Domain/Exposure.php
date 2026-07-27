<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain;

use App\Modules\Shared\Support\IntMath;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Rial;

/** F18 result — what a member still owes, in gold and in rial. */
final readonly class Exposure
{
    public function __construct(
        public int $goldMg,
        public int $rial,
        public int $settlementGoldMg = 0,
        public int $openOrderGoldMg = 0,
        public int $settlementRial = 0,
        public int $openOrderRial = 0,
    ) {}

    public static function zero(): self
    {
        return new self(0, 0);
    }

    public function gold(): FineWeight
    {
        return FineWeight::fromMilligrams($this->goldMg);
    }

    public function money(): Rial
    {
        return Rial::fromRial($this->rial);
    }

    public function withAdditionalGold(int $milligrams): int
    {
        return IntMath::add($this->goldMg, $milligrams);
    }

    public function withAdditionalRial(int $amount): int
    {
        return IntMath::add($this->rial, $amount);
    }

    public function isZero(): bool
    {
        return $this->goldMg === 0 && $this->rial === 0;
    }
}
