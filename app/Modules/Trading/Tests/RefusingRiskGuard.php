<?php

declare(strict_types=1);

namespace App\Modules\Trading\Tests;

use App\Modules\Risk\Contracts\RiskDecision;
use App\Modules\Risk\Contracts\RiskGuardInterface;
use App\Modules\Risk\Contracts\TradeIntent;
use App\Modules\Shared\Exceptions\LimitExceededException;

/** Refuses every intent, so the rejection path can be asserted. */
final class RefusingRiskGuard implements RiskGuardInterface
{
    public function __construct(private readonly string $reason = 'limit exceeded') {}

    public function assertTradeAllowed(TradeIntent $intent): void
    {
        throw new LimitExceededException(
            limitType: 'DAILY_VOLUME',
            requested: $intent->fineWeightMg,
            limit: 0,
        );
    }

    public function evaluate(TradeIntent $intent): RiskDecision
    {
        return RiskDecision::rejected('LIMIT_EXCEEDED', $this->reason);
    }
}
