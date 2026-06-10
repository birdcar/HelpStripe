<?php

namespace App\Support\Automation;

use App\Enums\RuleAction;
use InvalidArgumentException;

/**
 * One action row of a rule: "{action} {value}".
 *
 * The value-object half of the actions JSON column (AutomationRule::actions
 * hydrates a list of these). `value` is action-specific: a category id for
 * set_category, a user id for assign_to/notify_user, a bool for set_urgent, a
 * status string for change_status, free text for add_private_note. ActionApplier
 * interprets it per action.
 *
 * Malformed shapes throw at hydration, exactly like Condition, so the engine
 * can skip a corrupt rule rather than mis-apply it.
 */
final class Action
{
    public function __construct(
        public readonly RuleAction $action,
        public readonly mixed $value,
    ) {}

    /**
     * Hydrate an action from its stored array shape.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (! isset($data['action'])) {
            throw new InvalidArgumentException('Action is missing an action type.');
        }

        $action = RuleAction::tryFrom((string) $data['action']);

        if ($action === null) {
            throw new InvalidArgumentException("Unknown rule action: {$data['action']}.");
        }

        return new self($action, $data['value'] ?? null);
    }

    /**
     * The array shape stored in the model's JSON column.
     *
     * @return array{action: string, value: mixed}
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action->value,
            'value' => $this->value,
        ];
    }
}
