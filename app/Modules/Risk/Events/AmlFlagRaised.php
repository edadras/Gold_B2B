<?php

declare(strict_types=1);

namespace App\Modules\Risk\Events;

/**
 * A rule matched and a flag row was written. Listeners must respect §12.10:
 * nothing derived from this event may reach the member.
 */
final readonly class AmlFlagRaised
{
    public function __construct(
        public int $flagId,
        public string $ruleCode,
        public int $organizationId,
        public string $severity,
        public string $action,
        public string $summary,
        public ?string $subjectType = null,
        public ?int $subjectId = null,
    ) {}
}
