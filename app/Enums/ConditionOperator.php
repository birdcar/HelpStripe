<?php

namespace App\Enums;

/**
 * How a condition compares a field against its target value.
 *
 * The set is deliberately small — HelpSpot's rule builder offers a comparable
 * handful. `contains` is case-insensitive (mail subjects and bodies vary
 * freely in case); `gt`/`lt` are numeric (age_hours, hours_since_last_note);
 * `is_null` ignores the value entirely (e.g. "assignee is null" = unassigned).
 */
enum ConditionOperator: string
{
    case Equals = 'equals';
    case NotEquals = 'not_equals';
    case Contains = 'contains';
    case GreaterThan = 'gt';
    case LessThan = 'lt';
    case IsNull = 'is_null';

    /**
     * Get the human-readable label for this operator.
     */
    public function label(): string
    {
        return match ($this) {
            self::Equals => 'equals',
            self::NotEquals => 'does not equal',
            self::Contains => 'contains',
            self::GreaterThan => 'is greater than',
            self::LessThan => 'is less than',
            self::IsNull => 'is empty',
        };
    }
}
