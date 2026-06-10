<?php

namespace App\Queries\Reports;

use App\Data\AgentReport;
use App\Enums\RequestStatus;
use App\Models\Request;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Per-staff-member performance.
 *
 * One row per current team member: how many open requests they're holding
 * right now, how many they resolved in the window, and their average
 * first-response time. Driven by the team roster, not by the request table —
 * so an agent with zero activity still gets a row of zeros (their absence
 * from the chart would read as "no data," which is the wrong story; a zero
 * row reads as "available capacity," which is the right one).
 *
 * Unassigned requests never appear here: the rows are keyed by member, and a
 * request with `assigned_to = null` matches no member.
 */
class AgentPerformance
{
    public function __construct(
        private Team $team,
        private CarbonImmutable $from,
        private CarbonImmutable $to,
    ) {}

    /**
     * Build one row per team member, ordered by name.
     *
     * @return Collection<int, AgentReport>
     */
    public function rows(): Collection
    {
        $members = $this->team->members()
            ->orderBy('name')
            ->get();

        $rows = [];

        foreach ($members as $member) {
            $rows[] = $this->row($member);
        }

        return collect($rows);
    }

    private function row(User $member): AgentReport
    {
        $assigned = Request::query()
            ->where('team_id', $this->team->id)
            ->where('assigned_to', $member->id);

        // "Open" is a current-state count — what's on their plate now —
        // deliberately NOT range-scoped (the range describes throughput, the
        // open count describes load).
        $openAssigned = (clone $assigned)
            ->whereIn('status', array_map(
                fn (RequestStatus $status) => $status->value,
                RequestStatus::open(),
            ))
            ->count();

        // Half-open window [from, to): inclusive lower bound, exclusive upper.
        $resolvedInRange = (clone $assigned)
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '>=', $this->from)
            ->where('resolved_at', '<', $this->to)
            ->count();

        // Average first-response over their assigned requests that were
        // created in range and actually answered — same null-on-no-samples
        // contract as CategoryPerformance.
        $responseMinutes = (clone $assigned)
            ->whereNotNull('first_responded_at')
            ->where('created_at', '>=', $this->from)
            ->where('created_at', '<', $this->to)
            ->get(['created_at', 'first_responded_at'])
            ->map(fn (Request $request) => $request->created_at->diffInMinutes($request->first_responded_at));

        return new AgentReport(
            id: $member->id,
            name: $member->name,
            openAssigned: $openAssigned,
            resolvedInRange: $resolvedInRange,
            avgFirstResponseMinutes: $responseMinutes->isEmpty()
                ? null
                : round((float) $responseMinutes->avg(), 1),
        );
    }
}
