<?php

namespace App\Support\Automation;

use App\Enums\RuleAction;
use App\Enums\RuleLayer;
use App\Support\Resend\InboundEmail;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Computes the request-create overrides a team's mail rules want to apply to an
 * inbound email.
 *
 * Mail rules are the one layer that acts *before* a request exists, so they
 * can't go through ActionApplier (there's nothing to apply to). Instead they
 * accumulate overrides — category, assignee, urgency — into a plain array that
 * ProcessInboundEmail folds into the CreateRequest payload. The request is then
 * "correct from birth" rather than created-then-edited.
 *
 * Rules run in position order and a later rule's action wins on the same field
 * (last-write-wins) — the ordering is exposed in the builder UI so a surprising
 * category is a config problem, not a hidden one. Only the create-relevant
 * actions are honored here (set_category/assign_to/set_urgent); a mail rule
 * carrying a request-only action like change_status is simply ignored.
 */
class MailRuleEvaluator
{
    public function __construct(
        private ConditionEvaluator $evaluator,
        private RuleEngine $engine,
    ) {}

    /**
     * @return array{category_id?: int, assigned_to?: int, is_urgent?: bool}
     */
    public function overridesFor(InboundEmail $email, int $teamId): array
    {
        $overrides = [];

        foreach ($this->engine->rulesFor($teamId, RuleLayer::Mail) as $rule) {
            try {
                $conditions = $rule->hydratedConditions();
                $actions = $rule->hydratedActions();
            } catch (Throwable $e) {
                Log::warning('Skipping malformed mail rule.', [
                    'rule_id' => $rule->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (! $this->evaluator->matches($conditions, $email)) {
                continue;
            }

            foreach ($actions as $action) {
                match ($action->action) {
                    RuleAction::SetCategory => $overrides['category_id'] = (int) $action->value,
                    RuleAction::AssignTo => $overrides['assigned_to'] = (int) $action->value,
                    RuleAction::SetUrgent => $overrides['is_urgent'] = (bool) $action->value,
                    // change_status / add_private_note / notify_user have no
                    // meaning before the request exists — ignored on mail rules.
                    default => null,
                };
            }
        }

        return $overrides;
    }
}
