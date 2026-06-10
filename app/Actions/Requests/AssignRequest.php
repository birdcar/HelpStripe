<?php

namespace App\Actions\Requests;

use App\Events\RequestAssigned;
use App\Models\Request;
use App\Models\User;
use App\Notifications\RequestAssignedNotification;

/**
 * Assign a request to a staff member — or unassign it (null assignee).
 *
 * Single-assignment is the product's core claim: `assigned_to` is one
 * nullable column, so a request can never be owned by two people. The
 * activity log (Request::getActivitylogOptions) records the change
 * automatically; this action's job is the event and the notification.
 */
class AssignRequest
{
    /**
     * Set (or clear) the request's assignee.
     *
     * @param  User|null  $assignee  the new owner; null unassigns
     * @param  User|null  $actor  who performed the assignment; null for system actions (Phase 6 automation)
     */
    public function handle(Request $request, ?User $assignee, ?User $actor = null): Request
    {
        $previousAssignee = $request->assignee;

        $request->update(['assigned_to' => $assignee?->id]);

        RequestAssigned::dispatch($request, $assignee, $previousAssignee);

        // Grabbing your own ticket shouldn't ping you — only notify when
        // someone *else* (or the system) hands you work.
        if ($assignee !== null && ($actor === null || $assignee->isNot($actor))) {
            $assignee->notify(new RequestAssignedNotification($request, $actor));
        }

        return $request;
    }
}
