<?php

declare(strict_types=1);

namespace App\Modules\Risk\Contracts;

use App\Modules\Risk\Aml\AmlContext;
use App\Modules\Risk\Aml\AmlEvaluation;
use App\Modules\Risk\Exceptions\AmlBlockedException;

/**
 * Entry point other modules use to run an event past the AML rule set.
 */
interface AmlEvaluatorInterface
{
    /** Evaluates every active rule and persists any flags they raise. */
    public function evaluate(AmlContext $context): AmlEvaluation;

    /**
     * Same, but throws when a rule with action BLOCK matched.
     *
     * @throws AmlBlockedException
     */
    public function assertAllowed(AmlContext $context): AmlEvaluation;
}
