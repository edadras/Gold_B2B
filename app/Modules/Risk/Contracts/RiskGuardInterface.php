<?php

declare(strict_types=1);

namespace App\Modules\Risk\Contracts;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * The pre-trade gate. Trading calls assertTradeAllowed() on the hot path and
 * evaluate() when it wants to show the member why something would fail.
 */
interface RiskGuardInterface
{
    /**
     * @throws DomainException on the first check that fails
     */
    public function assertTradeAllowed(TradeIntent $intent): void;

    /** Same checks, same order, but returns the verdict instead of throwing. */
    public function evaluate(TradeIntent $intent): RiskDecision;
}
