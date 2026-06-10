<?php

namespace App\Support\Automation;

use App\Enums\ConditionField;
use App\Enums\ConditionOperator;
use InvalidArgumentException;

/**
 * One condition row of a rule: "{field} {operator} {value}".
 *
 * This is the value-object half of the JSON-cast → value-object mapping the
 * model performs (AutomationRule::conditions hydrates a list of these). Keeping
 * the engine working with typed objects rather than raw `['field' => …]` arrays
 * means a malformed hand-edited rule fails loudly *here*, at hydration, where
 * the RuleEngine catches it and skips the whole rule — instead of silently
 * mis-evaluating deep inside the matcher.
 *
 * `value` stays mixed: a category id (int), an urgent flag (bool), a substring
 * (string), or null for `is_null`. ConditionEvaluator coerces per operator.
 */
final class Condition
{
    public function __construct(
        public readonly ConditionField $field,
        public readonly ConditionOperator $operator,
        public readonly mixed $value,
    ) {}

    /**
     * Hydrate a condition from its stored array shape.
     *
     * Throws on any unknown enum value or missing key — the RuleEngine treats a
     * throw as "this rule is malformed, skip it and log" (the spec's
     * hand-edited-JSON failure mode).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (! isset($data['field'], $data['operator'])) {
            throw new InvalidArgumentException('Condition is missing a field or operator.');
        }

        $field = ConditionField::tryFrom((string) $data['field']);
        $operator = ConditionOperator::tryFrom((string) $data['operator']);

        if ($field === null) {
            throw new InvalidArgumentException("Unknown condition field: {$data['field']}.");
        }

        if ($operator === null) {
            throw new InvalidArgumentException("Unknown condition operator: {$data['operator']}.");
        }

        return new self($field, $operator, $data['value'] ?? null);
    }

    /**
     * The array shape stored in the model's JSON column.
     *
     * @return array{field: string, operator: string, value: mixed}
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field->value,
            'operator' => $this->operator->value,
            'value' => $this->value,
        ];
    }
}
