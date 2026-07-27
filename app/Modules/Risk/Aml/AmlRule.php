<?php

declare(strict_types=1);

namespace App\Modules\Risk\Aml;

/**
 * One detection rule. Stateless: thresholds arrive as $parameters from the
 * aml_rules row so compliance can retune without a deploy (§12.9).
 */
interface AmlRule
{
    /** Catalogue code, e.g. 'PAT-01'. Must match aml_rules.code. */
    public function code(): string;

    /**
     * @param  array<string, mixed>  $parameters  from aml_rules.parameters
     */
    public function evaluate(AmlContext $context, array $parameters): RuleResult;
}
