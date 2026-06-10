<?php

namespace App\Listeners;

use App\Enums\RuleLayer;
use App\Events\NoteAdded;
use App\Events\RequestCreated;
use App\Events\RequestStatusChanged;
use App\Models\Request;
use App\Support\Automation\RuleEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Runs trigger-layer automation rules in response to domain events.
 *
 * One listener, three event handlers. Laravel's event discovery registers each
 * public `handle*` method by its first parameter's type (the same mechanism
 * that auto-registers SendPublicReplyEmail), so RequestCreated /
 * RequestStatusChanged / NoteAdded each route to the right method with no
 * manual binding.
 *
 * The loop guard: each handler bails immediately if RuleEngine::$applying is
 * set, which it is while ActionApplier is running a rule's effects. A trigger
 * action that fires its own event (a status-change rule that changes status)
 * would otherwise re-enter here and could loop. Because the queue runs sync in
 * a request/test context, the nested event fires *inside* the apply() call
 * stack where the flag is still true, so the guard catches it. Single-level
 * suppression — a named limitation in the tour doc.
 */
class EvaluateTriggers implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(private RuleEngine $engine) {}

    /**
     * A new request was created.
     */
    public function handleRequestCreated(RequestCreated $event): void
    {
        $this->run('request_created', $event->request);
    }

    /**
     * A request changed status.
     */
    public function handleRequestStatusChanged(RequestStatusChanged $event): void
    {
        $this->run('request_status_changed', $event->request);
    }

    /**
     * A note landed on a request's timeline.
     */
    public function handleNoteAdded(NoteAdded $event): void
    {
        $this->run('note_added', $event->note->request);
    }

    /**
     * Evaluate the team's active trigger rules for this event against the
     * request, unless automation is already applying (loop guard).
     */
    private function run(string $event, Request $request): void
    {
        if (RuleEngine::$applying) {
            return;
        }

        $rules = $this->engine->rulesFor($request->team_id, RuleLayer::Trigger, $event);

        $this->engine->runForRequest($rules, $request);
    }
}
