<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Contracts;

use App\Modules\Counterparty\Domain\ConcentrationLevel;
use App\Modules\Counterparty\Domain\ExposureMetric;

/**
 * Counterparty concentration for one member (docs/03-domain/10-counterparty.md §10.6).
 *
 * Shares are basis points of the total receivable, computed with integer
 * arithmetic — a percentage that says 68% while the underlying figure is 68.4%
 * is fine for a warning banner, but it must never be a rounded float that
 * drifts between two renderings of the same page.
 */
final readonly class ConcentrationReport
{
    /**
     * @param  list<ConcentrationEntry>  $entries  sorted by share, largest first
     */
    public function __construct(
        public int $organizationId,
        public ExposureMetric $metric,
        public int $totalReceivable,
        public array $entries,
        public int $topShareBps,
        public ConcentrationLevel $level,
        public int $warningBps,
        public int $seriousBps,
    ) {}

    public function isAlarming(): bool
    {
        return $this->level->isAlarming();
    }

    public function topCounterparty(): ?ConcentrationEntry
    {
        return $this->entries[0] ?? null;
    }

    /** @return list<ConcentrationEntry> */
    public function alarmingEntries(): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (ConcentrationEntry $e): bool => $e->level->isAlarming(),
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'metric' => $this->metric->value,
            'total_receivable' => $this->totalReceivable,
            'top_share_bps' => $this->topShareBps,
            'level' => $this->level->value,
            'warning_bps' => $this->warningBps,
            'serious_bps' => $this->seriousBps,
            'entries' => array_map(
                static fn (ConcentrationEntry $e): array => $e->toArray(),
                $this->entries,
            ),
        ];
    }
}
