<?php

declare(strict_types=1);

namespace App\Modules\Custody\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * A transformation would create or destroy metal without accounting for it.
 *
 * This is a bug-class failure, not a user error: it means the arithmetic in a
 * split/merge/melt broke the invariant
 * input_fine = Σ output_fine + loss_fine.
 */
final class WeightConservationException extends DomainException
{
    public function __construct(
        public readonly string $operation,
        public readonly string $dimension,
        public readonly int $inputMg,
        public readonly int $outputMg,
        public readonly int $lossMg,
    ) {
        parent::__construct(sprintf(
            '%s violates %s conservation: input %d != output %d + loss %d',
            $operation,
            $dimension,
            $inputMg,
            $outputMg,
            $lossMg,
        ));
    }

    public function errorCode(): string
    {
        return 'WEIGHT_CONSERVATION_VIOLATION';
    }

    public function userMessage(): string
    {
        return 'خطای داخلی در محاسبه وزن. عملیات انجام نشد.';
    }

    public function httpStatus(): int
    {
        return 500;
    }

    public function details(): array
    {
        return [
            'operation' => $this->operation,
            'dimension' => $this->dimension,
            'input_mg' => $this->inputMg,
            'output_mg' => $this->outputMg,
            'loss_mg' => $this->lossMg,
        ];
    }
}
