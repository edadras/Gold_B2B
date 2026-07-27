<?php

declare(strict_types=1);

namespace App\Modules\Trading\Domain;

/**
 * Who may see an RFQ, and whether the requester is named (§4.7).
 *
 * ANONYMOUS hides the requester's identity until the moment a quote is
 * accepted — rule 5 of the same section.
 */
enum RfqVisibility: string
{
    case SELECTED = 'SELECTED';
    case ALL_QUALIFIED = 'ALL_QUALIFIED';
    case ANONYMOUS = 'ANONYMOUS';

    public function requiresRecipientList(): bool
    {
        return $this === self::SELECTED;
    }

    public function hidesRequester(): bool
    {
        return $this === self::ANONYMOUS;
    }
}
