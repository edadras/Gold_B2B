<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

/** One cell of the system-health block of §1.9. */
final readonly class HealthIndicator
{
    public function __construct(
        public string $key,
        public string $label,
        public string $value,
        public string $tone,
    ) {}
}
