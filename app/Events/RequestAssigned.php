<?php

namespace App\Events;

use App\Models\Request;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a request's assignee changes — including unassignment,
 * which carries a null assignee.
 *
 * Plain event for now; Phase 6 triggers and Phase 7 broadcasting hook in
 * later without touching AssignRequest.
 */
class RequestAssigned
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Request $request,
        public ?User $assignee,
        public ?User $previousAssignee,
    ) {
        //
    }
}
