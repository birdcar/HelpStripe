<?php

namespace App\Queries\Reports;

use App\Enums\RequestStatus;
use App\Models\Request;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;

/**
 * The stat-card numbers at the top of the reports page.
 *
 * Unlike the other report queries, QueueSnapshot is NOT range-scoped — these
 * are point-in-time counts answering "what does the queue look like right
 * now?" Open work, unassigned work, urgent work, and the two SLA cards
 * (breached and the actionable overdue subset). The SLA counts route through
 * Request::scopeSlaBreached / scopeSlaOverdue so the cards agree with the
 * category table and with Phase 6 automation.
 */
class QueueSnapshot
{
    public function __construct(private Team $team) {}

    /**
     * @return array{open: int, unassigned: int, urgent: int, breached: int, overdue: int}
     */
    public function counts(): array
    {
        return [
            'open' => $this->open()->count(),
            'unassigned' => $this->open()->whereNull('assigned_to')->count(),
            'urgent' => $this->open()->where('is_urgent', true)->count(),
            'breached' => $this->base()->slaBreached()->count(),
            'overdue' => $this->base()->slaOverdue()->count(),
        ];
    }

    /**
     * A fresh team-scoped query for each card — counts must not share builder
     * state.
     *
     * @return Builder<Request>
     */
    private function base(): Builder
    {
        return Request::query()->where('team_id', $this->team->id);
    }

    /**
     * Team-scoped query narrowed to open (active/pending) requests — the
     * shared base for the open/unassigned/urgent cards.
     *
     * @return Builder<Request>
     */
    private function open(): Builder
    {
        return $this->base()->whereIn('status', array_map(
            fn (RequestStatus $status) => $status->value,
            RequestStatus::open(),
        ));
    }
}
