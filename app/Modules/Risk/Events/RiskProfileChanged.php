<?php

declare(strict_types=1);

namespace App\Modules\Risk\Events;

/**
 * Risk level or trading permission moved. §11.2: an upgrade is never automatic,
 * so $automatic is false whenever the level improved.
 */
final readonly class RiskProfileChanged
{
    /** @param array<string, mixed> $changes */
    public function __construct(
        public int $organizationId,
        public string $previousLevel,
        public string $newLevel,
        public bool $isTradingAllowed,
        public ?string $reason,
        public bool $automatic,
        public array $changes = [],
    ) {}
}
