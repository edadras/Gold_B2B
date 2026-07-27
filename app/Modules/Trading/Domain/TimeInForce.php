<?php

declare(strict_types=1);

namespace App\Modules\Trading\Domain;

/** Table of §4.2, docs/03-domain/04-trading.md. */
enum TimeInForce: string
{
    /** Valid until the session closes — the default. */
    case DAY = 'DAY';
    /** Good till cancelled: survives the close. */
    case GTC = 'GTC';
    /** Good till date: expires at expires_at. */
    case GTD = 'GTD';
    /** Immediate or cancel: fill what you can now, cancel the rest. */
    case IOC = 'IOC';
    /** Fill or kill: all of it right now, or nothing at all. */
    case FOK = 'FOK';

    /** IOC and FOK never rest in the book. */
    public function restsInBook(): bool
    {
        return ! in_array($this, [self::IOC, self::FOK], true);
    }

    public function requiresExpiry(): bool
    {
        return $this === self::GTD;
    }

    /** Cancelled by the session close (GTC explicitly survives it — §4.8). */
    public function expiresAtSessionClose(): bool
    {
        return $this === self::DAY;
    }
}
