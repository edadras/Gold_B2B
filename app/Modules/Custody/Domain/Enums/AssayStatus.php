<?php

declare(strict_types=1);

namespace App\Modules\Custody\Domain\Enums;

/** Assay certificate status — docs/03-domain/02-gold-lot-assay.md §2.4. */
enum AssayStatus: string
{
    case VALID = 'VALID';
    case SUPERSEDED = 'SUPERSEDED';
    case DISPUTED = 'DISPUTED';
    case REVOKED = 'REVOKED';

    /** Only a VALID certificate can back an ASSAYED purity source. */
    public function isAuthoritative(): bool
    {
        return $this === self::VALID;
    }

    public function isFinal(): bool
    {
        return $this === self::SUPERSEDED || $this === self::REVOKED;
    }
}
