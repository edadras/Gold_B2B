<?php

declare(strict_types=1);

namespace App\Modules\Risk\Aml\Rules;

use App\Modules\Risk\Aml\AmlContext;
use App\Modules\Risk\Aml\AmlRule;
use App\Modules\Risk\Aml\RuleResult;
use App\Modules\Risk\Domain\FlagSeverity;
use App\Modules\Risk\Infrastructure\Models\AmlFlag;

/**
 * CPT-01 — trading with a member who has an open flag of their own.
 *
 * Reads aml_flags, which Risk owns, so no cross-module hop is involved. The
 * flagged member is never named back to the initiator (§12.10).
 */
final class FlaggedCounterpartyRule implements AmlRule
{
    public function code(): string
    {
        return 'CPT-01';
    }

    public function evaluate(AmlContext $context, array $parameters): RuleResult
    {
        $counterparty = $context->otherSide();

        if ($counterparty === null || $counterparty === $context->organizationId) {
            return RuleResult::notApplicable();
        }

        $minSeverity = FlagSeverity::tryFrom((string) ($parameters['min_severity'] ?? 'MEDIUM'))
            ?? FlagSeverity::MEDIUM;

        $open = AmlFlag::query()
            ->open()
            ->where('organization_id', $counterparty)
            ->get()
            ->filter(fn (AmlFlag $flag): bool => $flag->severity->isAtLeast($minSeverity));

        if ($open->isEmpty()) {
            return RuleResult::pass();
        }

        return RuleResult::flag(
            FlagSeverity::HIGH,
            'معامله با عضو دارای پرچم فعال',
            [
                'counterparty_org_id' => $counterparty,
                'open_flag_count' => $open->count(),
                'min_severity' => $minSeverity->value,
            ],
        );
    }
}
