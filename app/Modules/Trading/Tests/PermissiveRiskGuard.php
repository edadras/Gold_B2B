<?php

declare(strict_types=1);

namespace App\Modules\Trading\Tests;

use App\Modules\Risk\Contracts\RiskDecision;
use App\Modules\Risk\Contracts\RiskGuardInterface;
use App\Modules\Risk\Contracts\TradeIntent;

/**
 * Lets everything through.
 *
 * The real RiskGuard reads member limits, KYC tier and collateral, none of
 * which the Trading suite is testing. Its own suite covers those; substituting
 * it here keeps a matching-engine failure from being reported as a risk failure
 * and vice versa. The one test that cares about the gate uses
 * RefusingRiskGuard instead.
 */
final class PermissiveRiskGuard implements RiskGuardInterface
{
    /** @var list<TradeIntent> */
    public array $seen = [];

    public function assertTradeAllowed(TradeIntent $intent): void
    {
        $this->seen[] = $intent;
    }

    public function evaluate(TradeIntent $intent): RiskDecision
    {
        $this->seen[] = $intent;

        return RiskDecision::allowed();
    }
}
