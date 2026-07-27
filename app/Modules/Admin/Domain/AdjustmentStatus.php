<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain;

/**
 * Lifecycle of a manual ledger adjustment *request*.
 *
 * docs/08-frontend-web/01-web-panels.md §1.10 — the screen creates a request,
 * never an adjustment. Only APPROVED → POSTED writes to the ledger, and that
 * step needs a second human with a different role.
 */
enum AdjustmentStatus: string
{
    case PENDING_APPROVAL = 'PENDING_APPROVAL';
    case APPROVED = 'APPROVED';
    case POSTED = 'POSTED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING_APPROVAL => 'در انتظار تأیید',
            self::APPROVED => 'تأییدشده',
            self::POSTED => 'ثبت‌شده در دفتر',
            self::REJECTED => 'ردشده',
            self::CANCELLED => 'لغوشده',
        };
    }

    /** Badge tone used by the status-badge partial. */
    public function tone(): string
    {
        return match ($this) {
            self::PENDING_APPROVAL => 'warn',
            self::APPROVED => 'info',
            self::POSTED => 'ok',
            self::REJECTED, self::CANCELLED => 'bad',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::POSTED, self::REJECTED, self::CANCELLED], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
