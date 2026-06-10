<?php

namespace App\Events;

use App\Models\Request;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a new helpdesk request is created, whatever the channel —
 * agent UI now, email (Phase 3), portal (Phase 4), API (Phase 3).
 *
 * Deliberately a *plain* event in this phase: no ShouldBroadcast, no
 * channels. Phase 6's trigger engine listens to it, and Phase 7 upgrades
 * it to broadcast — neither change touches the code that fires it.
 *
 * SerializesModels matters once queued listeners subscribe: the event is
 * stored with model identifiers and re-hydrated fresh from the database
 * when the listener runs, not with a stale serialized copy.
 */
class RequestCreated
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public Request $request)
    {
        //
    }
}
