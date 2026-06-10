<?php

namespace App\Actions\Requests;

use App\Enums\RequestStatus;
use App\Events\RequestStatusChanged;
use App\Models\Request;

/**
 * Transition a request between statuses.
 *
 * Owns the `resolved_at` lifecycle: entering Resolved or Closed stamps
 * it (first time only — moving Resolved → Closed keeps the original
 * resolution time), reopening to Active or Pending clears it. Phase 8's
 * resolution-time report depends on these semantics.
 */
class ChangeStatus
{
    /**
     * Move the request to a new status.
     */
    public function handle(Request $request, RequestStatus $newStatus): Request
    {
        $oldStatus = $request->status;

        if ($oldStatus === $newStatus) {
            return $request;
        }

        $resolvedAt = match (true) {
            in_array($newStatus, RequestStatus::open(), true) => null,
            default => $request->resolved_at ?? now(),
        };

        $request->update([
            'status' => $newStatus,
            'resolved_at' => $resolvedAt,
        ]);

        RequestStatusChanged::dispatch($request, $oldStatus, $newStatus);

        return $request;
    }
}
