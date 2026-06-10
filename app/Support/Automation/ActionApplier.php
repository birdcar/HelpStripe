<?php

namespace App\Support\Automation;

use App\Actions\Requests\AddNote;
use App\Actions\Requests\AssignRequest;
use App\Actions\Requests\ChangeStatus;
use App\Enums\RequestSource;
use App\Enums\RequestStatus;
use App\Enums\RuleAction;
use App\Models\Category;
use App\Models\Request;
use App\Models\User;
use App\Notifications\AutomationNotification;
use Throwable;

/**
 * Executes a rule's actions against a request.
 *
 * The cardinal rule: automation NEVER writes models directly. Every effect goes
 * through a Phase 2 action class (AssignRequest, ChangeStatus, AddNote) or the
 * request's own update path under the loop guard — so the activity log, domain
 * events, notifications, and first-response bookkeeping all stay correct, and
 * the trigger-loop guard has a single choke point.
 *
 * The `cause` label (e.g. "Mail Rule: Billing route") is written to the
 * activity log as a marker entry, so the request history reads *what automated
 * the change*, sitting alongside the field diffs the action classes already log
 * — the demo's money shot.
 *
 * Loop guard: `apply()` flips the RuleEngine::$applying flag for the duration of
 * the effects. Trigger actions emit their own domain events (a status change
 * fires RequestStatusChanged); without the guard those would re-enter
 * EvaluateTriggers and could loop. The guard makes EvaluateTriggers skip events
 * born of automation. Single-level suppression — a named limitation.
 */
class ActionApplier
{
    public function __construct(
        private AssignRequest $assignRequest,
        private ChangeStatus $changeStatus,
        private AddNote $addNote,
    ) {}

    /**
     * Apply every action of a rule to the request, under the loop guard.
     *
     * @param  list<Action>  $actions
     */
    public function apply(array $actions, Request $request, string $cause): void
    {
        RuleEngine::$applying = true;

        try {
            // A marker so the timeline/history shows the automation that ran,
            // even for rules whose only action is a notification (no field
            // diff). Logged once per rule firing, before the effects.
            activity()
                ->performedOn($request)
                ->withProperties(['cause' => $cause])
                ->log($cause);

            foreach ($actions as $action) {
                $this->applyAction($action, $request, $cause);
            }
        } finally {
            // try/finally guarantees the flag clears even if an action throws —
            // a stuck flag would silently disable every trigger thereafter.
            RuleEngine::$applying = false;
        }
    }

    /**
     * Execute a single action, skipping (with a private note) when it
     * references an entity that no longer exists.
     */
    private function applyAction(Action $action, Request $request, string $cause): void
    {
        switch ($action->action) {
            case RuleAction::SetCategory:
                $category = Category::query()
                    ->where('team_id', $request->team_id)
                    ->find((int) $action->value);

                if ($category === null) {
                    $this->skip($request, $cause, 'set the category (the category no longer exists)');

                    return;
                }

                $request->update(['category_id' => $category->id]);
                break;

            case RuleAction::AssignTo:
                $assignee = User::query()->find((int) $action->value);

                if ($assignee === null) {
                    $this->skip($request, $cause, 'assign the request (the user no longer exists)');

                    return;
                }

                // actor = null: automation made the assignment, so the
                // notification reads "HelpStripe assigned…" and a self-assign
                // check has no actor to compare against (the assignee is still
                // notified — see AssignRequest).
                $this->assignRequest->handle($request, $assignee, actor: null);
                break;

            case RuleAction::SetUrgent:
                $request->update(['is_urgent' => (bool) $action->value]);
                break;

            case RuleAction::ChangeStatus:
                $status = RequestStatus::tryFrom((string) $action->value);

                if ($status === null) {
                    $this->skip($request, $cause, 'change the status (unknown status value)');

                    return;
                }

                $this->changeStatus->handle($request, $status);
                break;

            case RuleAction::AddPrivateNote:
                // Authored by the assignee if there is one, else the team's
                // first member — a note needs an author (no system user
                // exists), mirroring the inbound pipeline's choice for its
                // "attachment skipped" notes.
                $this->addNote->handle(
                    $request,
                    $this->systemAuthor($request),
                    (string) $action->value,
                    isPrivate: true,
                    source: RequestSource::Agent,
                );
                break;

            case RuleAction::NotifyUser:
                $user = User::query()->find((int) $action->value);

                if ($user === null) {
                    $this->skip($request, $cause, 'notify a user (the user no longer exists)');

                    return;
                }

                $user->notify(new AutomationNotification($request, $cause));
                break;
        }
    }

    /**
     * Record that an action couldn't run — never silently drop it.
     *
     * The spec's error-handling row: an action referencing a deleted entity is
     * skipped, a private note explains why, and the rest of the rule continues.
     */
    private function skip(Request $request, string $cause, string $whatFailed): void
    {
        try {
            $this->addNote->handle(
                $request,
                $this->systemAuthor($request),
                __('Automation (:cause) could not :what — action skipped.', [
                    'cause' => $cause,
                    'what' => $whatFailed,
                ]),
                isPrivate: true,
                source: RequestSource::Agent,
            );
        } catch (Throwable) {
            // If even the note can't be written (no author at all), there's
            // nothing left to do but let the rule continue — the activity-log
            // marker above already records that the rule fired.
        }
    }

    /**
     * The staff member a system-authored note hangs off: the assignee, or the
     * team's first member as a fallback.
     */
    private function systemAuthor(Request $request): User
    {
        return $request->assignee ?? $request->team->members()->oldest('users.id')->firstOrFail();
    }
}
