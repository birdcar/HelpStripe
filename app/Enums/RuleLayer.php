<?php

namespace App\Enums;

/**
 * Which of HelpSpot's three automation layers a rule belongs to.
 *
 * All three layers share one `automation_rules` table and one engine; the
 * layer column is what tells the engine *when* a rule runs:
 *
 *  - Mail: evaluated inside the inbound email pipeline, against the raw
 *    InboundEmail, before a request is created (set category/urgent/assignee
 *    at birth).
 *  - Trigger: evaluated synchronously-after a domain event fires
 *    (RequestCreated, RequestStatusChanged, NoteAdded) via a queued listener.
 *  - Scheduled: evaluated by `automation:run` against the whole open queue on
 *    a timer (e.g. "unanswered for 24h → mark urgent").
 *
 * One table, three layers is a deliberate teaching tradeoff (vs three tables):
 * the shapes are identical — conditions + actions JSON — so a single model,
 * migration, and engine cover all three. The cost is that a few columns are
 * layer-specific (`event` only matters for triggers); the builder UI hides
 * what doesn't apply.
 */
enum RuleLayer: string
{
    case Mail = 'mail';
    case Trigger = 'trigger';
    case Scheduled = 'scheduled';

    /**
     * Get the human-readable label for this layer.
     */
    public function label(): string
    {
        return match ($this) {
            self::Mail => 'Mail Rule',
            self::Trigger => 'Trigger',
            self::Scheduled => 'Automation Rule',
        };
    }

    /**
     * The condition fields that make sense for this layer's subject type.
     *
     * Mail rules evaluate an InboundEmail (no request exists yet), so only the
     * email-shaped fields apply. Trigger and Scheduled rules evaluate a
     * Request, so they get the request-shaped fields. The builder UI uses this
     * to constrain the field <select> per layer — a named mitigation for the
     * "age_hours on a mail rule silently never matches" confusion.
     *
     * @return list<ConditionField>
     */
    public function conditionFields(): array
    {
        return match ($this) {
            self::Mail => [
                ConditionField::Subject,
                ConditionField::Body,
                ConditionField::FromEmail,
                ConditionField::ToMailbox,
            ],
            self::Trigger, self::Scheduled => [
                ConditionField::Subject,
                ConditionField::Body,
                ConditionField::Category,
                ConditionField::Status,
                ConditionField::Assignee,
                ConditionField::IsUrgent,
                ConditionField::AgeHours,
                ConditionField::HoursSinceLastNote,
            ],
        };
    }
}
