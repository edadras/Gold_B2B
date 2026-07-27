<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application;

use App\Modules\Risk\Aml\AmlContext;
use App\Modules\Risk\Aml\AmlEvaluation;
use App\Modules\Risk\Aml\AmlRuleRegistry;
use App\Modules\Risk\Aml\RuleResult;
use App\Modules\Risk\Contracts\AmlEvaluatorInterface;
use App\Modules\Risk\Domain\AmlRuleAction;
use App\Modules\Risk\Domain\FlagStatus;
use App\Modules\Risk\Domain\RuleOutcome;
use App\Modules\Risk\Events\AmlFlagRaised;
use App\Modules\Risk\Exceptions\AmlBlockedException;
use App\Modules\Risk\Infrastructure\Models\AmlFlag;
use App\Modules\Risk\Infrastructure\Models\AmlRuleModel;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * docs/03-domain/12-aml-compliance.md §12.2.
 *
 * Loads the active catalogue, runs every rule that applies to the event, writes
 * a flag for each match whose configured action is not LOG, and returns the
 * worst outcome. Events are dispatched after the transaction commits.
 *
 * A rule that throws is contained: one broken detector must not stop the
 * others, and it must never take a trade down with it. The failure is recorded
 * against the rule code in the results.
 */
final class AmlRuleEngine implements AmlEvaluatorInterface
{
    public function __construct(
        private readonly AmlRuleRegistry $registry,
        private readonly Dispatcher $events,
    ) {}

    public function evaluate(AmlContext $context): AmlEvaluation
    {
        /** @var list<AmlRuleModel> $configured */
        $configured = AmlRuleModel::query()
            ->active()
            ->orderBy('evaluation_order')
            ->orderBy('code')
            ->get()
            ->all();

        $results = [];
        $matches = [];
        $outcome = RuleOutcome::PASS;
        $blocking = [];

        foreach ($configured as $model) {
            if (! $model->appliesTo($context->eventType->value)) {
                continue;
            }

            $rule = $this->registry->get($model->code);

            if ($rule === null) {
                continue;
            }

            try {
                $result = $rule->evaluate($context, $model->parameters);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            $results[$model->code] = $result;

            if (! $result->matched()) {
                continue;
            }

            $effective = $this->effectiveOutcome($model->action, $result, $context);

            if ($effective->isWorseThan($outcome)) {
                $outcome = $effective;
            }

            if ($effective === RuleOutcome::BLOCK) {
                $blocking[] = $model->code;
            }

            if ($model->action->raisesFlag()) {
                $matches[] = [$model, $result];
            }
        }

        $flagIds = $this->persistFlags($context, $matches);

        return new AmlEvaluation($outcome, $results, $flagIds, $blocking);
    }

    public function assertAllowed(AmlContext $context): AmlEvaluation
    {
        $evaluation = $this->evaluate($context);

        if ($evaluation->isBlocked()) {
            throw new AmlBlockedException($evaluation->blockingRuleCodes);
        }

        return $evaluation;
    }

    /**
     * The stored action has the final say, except that an event which cannot be
     * blocked (a trade that already happened) degrades BLOCK to FLAG — there is
     * nothing left to stop.
     */
    private function effectiveOutcome(AmlRuleAction $action, RuleResult $result, AmlContext $context): RuleOutcome
    {
        $wantsBlock = $action->blocksOperation() || $result->outcome === RuleOutcome::BLOCK;

        if ($wantsBlock && $context->eventType->canBlock()) {
            return RuleOutcome::BLOCK;
        }

        return RuleOutcome::FLAG;
    }

    /**
     * @param  list<array{0:AmlRuleModel,1:RuleResult}>  $matches
     * @return list<int>
     */
    private function persistFlags(AmlContext $context, array $matches): array
    {
        if ($matches === []) {
            return [];
        }

        /** @var list<AmlFlag> $flags */
        $flags = DB::transaction(function () use ($context, $matches): array {
            $created = [];

            foreach ($matches as [$model, $result]) {
                $created[] = AmlFlag::query()->create([
                    'rule_code' => $model->code,
                    'organization_id' => $context->organizationId,
                    'user_id' => $context->userId,
                    'severity' => ($result->severity ?? $model->severity)->value,
                    'status' => FlagStatus::OPEN->value,
                    'summary' => $result->summary ?? $model->name,
                    'context' => $result->context,
                    'subject_type' => $context->subjectType,
                    'subject_id' => $context->subjectId,
                    'raised_at' => CarbonImmutable::now(),
                ]);
            }

            return $created;
        });

        $ids = [];

        foreach ($flags as $index => $flag) {
            [$model, $result] = $matches[$index];
            $ids[] = (int) $flag->id;

            // Rule 3: after commit.
            $this->events->dispatch(new AmlFlagRaised(
                flagId: (int) $flag->id,
                ruleCode: $model->code,
                organizationId: $context->organizationId,
                severity: $flag->severity->value,
                action: $model->action->value,
                summary: $flag->summary,
                subjectType: $context->subjectType,
                subjectId: $context->subjectId,
            ));
        }

        return $ids;
    }
}
