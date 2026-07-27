<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

enum ReportJobStatus: string
{
    case QUEUED = 'QUEUED';
    case RUNNING = 'RUNNING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
    case EXPIRED = 'EXPIRED';

    /** @return array<int, self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::QUEUED => [self::RUNNING, self::FAILED],
            self::RUNNING => [self::COMPLETED, self::FAILED],
            self::COMPLETED => [self::EXPIRED],
            self::FAILED, self::EXPIRED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function isDownloadable(): bool
    {
        return $this === self::COMPLETED;
    }
}
