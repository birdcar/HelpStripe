<?php

namespace App\Enums;

/**
 * The fields a rule condition can test.
 *
 * A field is resolved differently depending on the subject being matched: an
 * InboundEmail (mail layer) exposes `from_email`/`to_mailbox`/`subject`/`body`;
 * a Request (trigger/scheduled layers) exposes `category`/`status`/`assignee`/
 * `is_urgent`/`age_hours`/`hours_since_last_note` (plus subject/body via its
 * opening note). ConditionEvaluator owns that resolution — a field that has no
 * meaning for the current subject simply doesn't match (returns false), which
 * the builder UI prevents by filtering choices per layer (see
 * RuleLayer::conditionFields()).
 */
enum ConditionField: string
{
    case Subject = 'subject';
    case Body = 'body';
    case FromEmail = 'from_email';
    case ToMailbox = 'to_mailbox';
    case Category = 'category';
    case Status = 'status';
    case Assignee = 'assignee';
    case IsUrgent = 'is_urgent';
    case AgeHours = 'age_hours';
    case HoursSinceLastNote = 'hours_since_last_note';

    /**
     * Get the human-readable label for this field.
     */
    public function label(): string
    {
        return match ($this) {
            self::Subject => 'Subject',
            self::Body => 'Body',
            self::FromEmail => 'From email',
            self::ToMailbox => 'To mailbox',
            self::Category => 'Category',
            self::Status => 'Status',
            self::Assignee => 'Assignee',
            self::IsUrgent => 'Is urgent',
            self::AgeHours => 'Age (hours)',
            self::HoursSinceLastNote => 'Hours since last note',
        };
    }
}
