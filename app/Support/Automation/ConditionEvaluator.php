<?php

namespace App\Support\Automation;

use App\Enums\ConditionField;
use App\Enums\ConditionOperator;
use App\Models\Request;
use App\Support\Resend\InboundEmail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Matches a rule's conditions against a subject.
 *
 * The subject is either an InboundEmail (mail layer) or a Request
 * (trigger/scheduled layers). Field resolution is the only part that differs
 * between them — `from_email` only means something on an email, `age_hours`
 * only on a request — so resolution is split by subject type and an
 * unsupported field simply yields null (and therefore doesn't match anything
 * but `is_null`). The builder UI constrains field choices per layer so this
 * "silent non-match" is never hit in practice, but the evaluator stays safe if
 * a rule is hand-edited across layers.
 *
 * Condition semantics are AND across rows (HelpSpot-style; OR is a Future
 * Consideration), and an empty conditions array matches everything — a rule
 * with no conditions is "always fire," which the scheduled/trigger demos rely
 * on. This is a pure matcher: no DB writes, no side effects.
 */
class ConditionEvaluator
{
    /**
     * Does the subject satisfy every condition?
     *
     * @param  list<Condition>  $conditions
     */
    public function matches(array $conditions, Request|InboundEmail $subject): bool
    {
        // Empty conditions = "always". Documented behavior — the engine treats
        // a rule with no conditions as unconditionally matching.
        foreach ($conditions as $condition) {
            if (! $this->matchesCondition($condition, $subject)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluate a single condition against the subject.
     */
    private function matchesCondition(Condition $condition, Request|InboundEmail $subject): bool
    {
        $actual = $this->resolve($condition->field, $subject);

        return match ($condition->operator) {
            ConditionOperator::Equals => $this->looselyEquals($actual, $condition->value),
            ConditionOperator::NotEquals => ! $this->looselyEquals($actual, $condition->value),
            // contains is case-insensitive substring; a null field never
            // contains anything.
            ConditionOperator::Contains => $actual !== null
                && Str::contains(Str::lower((string) $actual), Str::lower((string) $condition->value)),
            ConditionOperator::GreaterThan => is_numeric($actual) && is_numeric($condition->value)
                && (float) $actual > (float) $condition->value,
            ConditionOperator::LessThan => is_numeric($actual) && is_numeric($condition->value)
                && (float) $actual < (float) $condition->value,
            ConditionOperator::IsNull => $actual === null,
        };
    }

    /**
     * Compare two values for equality without PHP's type juggling surprises.
     *
     * Booleans compare as booleans (so `is_urgent equals true` works whether
     * the stored value is `true` or `1`); everything else compares as strings,
     * which is right for ids and enum values arriving from JSON as either ints
     * or numeric strings.
     */
    private function looselyEquals(mixed $actual, mixed $expected): bool
    {
        if ($actual === null || $expected === null) {
            return $actual === $expected;
        }

        if (is_bool($actual) || is_bool($expected)) {
            return (bool) $actual === (bool) $expected;
        }

        return (string) $actual === (string) $expected;
    }

    /**
     * Resolve a field to its current value on the subject.
     *
     * Returns null for a field that has no meaning on this subject type — the
     * evaluator then treats it as a non-match for every operator except
     * is_null.
     */
    private function resolve(ConditionField $field, Request|InboundEmail $subject): mixed
    {
        return $subject instanceof Request
            ? $this->resolveRequest($field, $subject)
            : $this->resolveEmail($field, $subject);
    }

    /**
     * Resolve a field against an inbound email (mail layer).
     */
    private function resolveEmail(ConditionField $field, InboundEmail $email): mixed
    {
        return match ($field) {
            ConditionField::Subject => $email->subject,
            ConditionField::Body => $email->body,
            ConditionField::FromEmail => $email->fromEmail,
            ConditionField::ToMailbox => $email->primaryTo(),
            // Request-only fields don't apply to an email — null = non-match.
            default => null,
        };
    }

    /**
     * Resolve a field against a request (trigger/scheduled layers).
     */
    private function resolveRequest(ConditionField $field, Request $request): mixed
    {
        return match ($field) {
            ConditionField::Subject => $request->subject,
            // The request body is its opening customer message — the oldest
            // note. Loaded lazily; cheap at the scale rules run.
            ConditionField::Body => $request->notes()->orderBy('id')->value('body'),
            ConditionField::Category => $request->category_id,
            ConditionField::Status => $request->status->value,
            ConditionField::Assignee => $request->assigned_to,
            ConditionField::IsUrgent => $request->is_urgent,
            // Whole hours since the request opened, measured against the app's
            // clock (Carbon::now respects setTestNow, so frozen-time tests are
            // honest). diffInHours is unsigned-by-absolute by default in modern
            // Carbon; created_at is always in the past so the sign is moot.
            ConditionField::AgeHours => $request->created_at === null
                ? null
                : (int) $request->created_at->diffInHours(Carbon::now()),
            ConditionField::HoursSinceLastNote => $this->hoursSinceLastNote($request),
            // Email-only fields don't apply to a request — null = non-match.
            default => null,
        };
    }

    /**
     * Whole hours since the most recent note on the request, or null if it has
     * no notes yet (every request has its opening note, so null is rare but
     * possible mid-transaction).
     */
    private function hoursSinceLastNote(Request $request): ?int
    {
        /** @var string|null $latest */
        $latest = $request->notes()->max('created_at');

        if ($latest === null) {
            return null;
        }

        return (int) Carbon::parse($latest)->diffInHours(Carbon::now());
    }
}
