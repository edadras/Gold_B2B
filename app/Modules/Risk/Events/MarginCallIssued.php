<?php

declare(strict_types=1);

namespace App\Modules\Risk\Events;

/** Coverage fell into the margin-call band (F20 / §11.7). */
final readonly class MarginCallIssued
{
    public function __construct(
        public int $organizationId,
        public int $coverageRatioBps,
        public int $collateralValueRial,
        public int $exposureValueRial,
        public int $shortfallRial,
        public ?int $deadlineHours,
        public string $status,
    ) {}
}
