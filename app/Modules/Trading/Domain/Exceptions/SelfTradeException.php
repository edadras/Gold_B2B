<?php

declare(strict_types=1);

namespace App\Modules\Trading\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * An organisation tried to trade with itself (§4.4).
 *
 * Wash trading manipulates volume and price, is an AML red flag and has no
 * economic meaning, so it is refused at three layers: the matching query, the
 * re-check before a trade row is written, and the chk_no_self_trade constraint.
 */
final class SelfTradeException extends DomainException
{
    public function __construct(public readonly int $organizationId)
    {
        parent::__construct("Organization {$organizationId} cannot trade with itself");
    }

    public function errorCode(): string
    {
        return 'SELF_TRADE_FORBIDDEN';
    }

    public function userMessage(): string
    {
        return 'معامله با خود مجاز نیست.';
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return ['organization_id' => $this->organizationId];
    }
}
