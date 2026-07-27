<?php

declare(strict_types=1);

namespace App\Modules\Risk\Aml\Rules;

use App\Modules\Risk\Aml\AmlContext;
use App\Modules\Risk\Aml\AmlRule;
use App\Modules\Risk\Aml\RuleResult;
use App\Modules\Risk\Domain\FlagSeverity;

/**
 * CPT-04 — a member trading with itself. §12.3 category E, severity CRITICAL.
 *
 * There is no legitimate reason for it and it is the cheapest wash-trading
 * technique there is, so this one blocks rather than flags. §11.2 also docks
 * 50 points of credit score for the attempt.
 */
final class SelfTradeRule implements AmlRule
{
    public function code(): string
    {
        return 'CPT-04';
    }

    public function evaluate(AmlContext $context, array $parameters): RuleResult
    {
        if (! $context->isSelfTrade()) {
            return RuleResult::pass();
        }

        return RuleResult::block(
            FlagSeverity::CRITICAL,
            'تلاش برای Self-Trade',
            [
                'organization_id' => $context->organizationId,
                'buyer_org_id' => $context->buyerOrganizationId,
                'seller_org_id' => $context->sellerOrganizationId,
                'fine_weight_mg' => $context->fineWeightMg,
                'user_id' => $context->userId,
            ],
        );
    }
}
