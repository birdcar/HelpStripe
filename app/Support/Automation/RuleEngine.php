<?php

namespace App\Support\Automation;

use App\Enums\RuleLayer;
use App\Models\AutomationRule;
use App\Models\Request;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The shared core of all three automation layers.
 *
 * The mail pipeline, the trigger listener, and the scheduled command all funnel
 * through here: each hands the engine a subject (a Request) and a set of rules,
 * and the engine evaluates conditions then applies the matched rules' actions.
 * Centralizing it means the loop guard, the malformed-rule handling, and the
 * cause-label convention live in exactly one place.
 *
 * (Mail rules are the one exception to "apply via the engine": they accumulate
 * overrides into the request-create payload rather than mutating a request that
 * doesn't exist yet, so ProcessInboundEmail drives the ConditionEvaluator
 * directly. The trigger/scheduled layers use runForRequest below.)
 */
class RuleEngine
{
    /**
     * Loop guard. Set true by ActionApplier for the duration of a rule's
     * effects; EvaluateTriggers checks it and skips events emitted while an
     * action is running, so a trigger can't re-fire itself into a loop.
     *
     * Static (not injected) because the guard must span the whole call stack of
     * an apply — including queued listeners resolved fresh from the container —
     * within one process. Single-level suppression; documented limitation.
     */
    public static bool $applying = false;

    public function __construct(
        private ConditionEvaluator $evaluator,
        private ActionApplier $applier,
    ) {}

    /**
     * Run every active rule of a layer against a request, applying matches.
     *
     * Used by the trigger and scheduled layers. Rules are evaluated in position
     * order; a malformed (hand-edited) rule whose JSON won't hydrate is logged
     * and skipped so one bad row can't halt the rest. Returns how many rules
     * fired (handy for the command's output).
     *
     * @param  iterable<AutomationRule>  $rules
     */
    public function runForRequest(iterable $rules, Request $request): int
    {
        $fired = 0;

        foreach ($rules as $rule) {
            if ($this->runRule($rule, $request)) {
                $fired++;
            }
        }

        return $fired;
    }

    /**
     * Evaluate one rule against a request and apply its actions if it matches.
     *
     * Returns true when the rule matched (and was applied), false otherwise.
     */
    public function runRule(AutomationRule $rule, Request $request): bool
    {
        try {
            $conditions = $rule->hydratedConditions();
            $actions = $rule->hydratedActions();
        } catch (Throwable $e) {
            // The hand-edited-JSON failure mode: a rule whose stored shape won't
            // hydrate into value objects is skipped, logged, and the engine
            // moves on. Better a silent skip + log line than a 500 that takes
            // down the whole inbound pipeline or scheduled run.
            Log::warning('Skipping malformed automation rule.', [
                'rule_id' => $rule->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if (! $this->evaluator->matches($conditions, $request)) {
            return false;
        }

        $this->applier->apply($actions, $request->refresh(), $rule->layer->label().': '.$rule->name);

        return true;
    }

    /**
     * Active rules of a layer for a team, in evaluation order.
     *
     * @return Collection<int, AutomationRule>
     */
    public function rulesFor(int $teamId, RuleLayer $layer, ?string $event = null): Collection
    {
        return AutomationRule::query()
            ->where('team_id', $teamId)
            ->activeLayer($layer)
            ->when($event !== null, fn ($query) => $query->where('event', $event))
            ->get();
    }
}
