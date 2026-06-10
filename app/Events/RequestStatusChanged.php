<?php

namespace App\Events;

use App\Enums\RequestStatus;
use App\Models\Request;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a request transitions between statuses. Carries both the
 * old and new status so listeners (Phase 6 triggers, Phase 8 reports)
 * can react to specific transitions — "anything → Resolved" — without
 * re-querying the activity log.
 */
class RequestStatusChanged
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Request $request,
        public RequestStatus $oldStatus,
        public RequestStatus $newStatus,
    ) {
        //
    }
}
