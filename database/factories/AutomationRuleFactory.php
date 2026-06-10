<?php

namespace Database\Factories;

use App\Enums\ConditionField;
use App\Enums\ConditionOperator;
use App\Enums\RuleAction;
use App\Enums\RuleLayer;
use App\Models\AutomationRule;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomationRule>
 */
class AutomationRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Defaults to a trigger-layer rule (the most general shape) with a single
     * always-matching subject condition and a set-urgent action — enough to
     * exercise the engine; layer-specific states below swap in realistic
     * conditions/actions.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'layer' => RuleLayer::Trigger,
            'event' => 'request_created',
            'name' => fake()->words(3, true),
            'is_active' => true,
            'position' => 0,
            'conditions' => [
                ['field' => ConditionField::Subject->value, 'operator' => ConditionOperator::Contains->value, 'value' => ''],
            ],
            'actions' => [
                ['action' => RuleAction::SetUrgent->value, 'value' => true],
            ],
        ];
    }

    /**
     * A mail-layer rule (evaluated against an inbound email, no event).
     */
    public function mail(): static
    {
        return $this->state(fn (array $attributes) => [
            'layer' => RuleLayer::Mail,
            'event' => null,
            'conditions' => [
                ['field' => ConditionField::Subject->value, 'operator' => ConditionOperator::Contains->value, 'value' => 'invoice'],
            ],
            'actions' => [
                ['action' => RuleAction::SetUrgent->value, 'value' => true],
            ],
        ]);
    }

    /**
     * A trigger-layer rule fired by the given domain event.
     */
    public function trigger(string $event = 'request_created'): static
    {
        return $this->state(fn (array $attributes) => [
            'layer' => RuleLayer::Trigger,
            'event' => $event,
        ]);
    }

    /**
     * A scheduled-layer rule (evaluated by automation:run, no event).
     */
    public function scheduled(): static
    {
        return $this->state(fn (array $attributes) => [
            'layer' => RuleLayer::Scheduled,
            'event' => null,
            'conditions' => [
                ['field' => ConditionField::AgeHours->value, 'operator' => ConditionOperator::GreaterThan->value, 'value' => 24],
            ],
            'actions' => [
                ['action' => RuleAction::SetUrgent->value, 'value' => true],
            ],
        ]);
    }

    /**
     * Mark the rule inactive (the engine skips it).
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
