<?php

declare(strict_types=1);

namespace App\Modules\Custody\Domain\Enums;

/** Who is physically holding the metal — docs/03-domain/06-custody-vault.md §6.2. */
enum CustodianType: string
{
    case VAULT = 'VAULT';
    case ORGANIZATION = 'ORGANIZATION';
    case LAB = 'LAB';
    case IN_TRANSIT = 'IN_TRANSIT';
    case THIRD_PARTY = 'THIRD_PARTY';

    /**
     * Only vault-held gold can change owner without physically moving.
     * This is the whole commercial incentive for members to use the vault.
     */
    public function allowsBookTransfer(): bool
    {
        return $this === self::VAULT;
    }

    /** LAB and IN_TRANSIT lock the lot completely. */
    public function isLocked(): bool
    {
        return $this === self::LAB || $this === self::IN_TRANSIT;
    }

    public function requiresPhysicalDelivery(): bool
    {
        return $this === self::ORGANIZATION || $this === self::THIRD_PARTY;
    }
}
