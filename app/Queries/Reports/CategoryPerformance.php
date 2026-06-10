<?php

namespace App\Queries\Reports;

use App\Data\CategoryReport;
use App\Models\Category;
use App\Models\Request;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Per-category performance — the SLA report.
 *
 * One row per category in the team: how many requests it took in the window,
 * the average first-response time, the category's SLA target, and how many of
 * those requests breached it. The breach count reuses Request::scopeSlaBreached
 * so this table and Phase 6's automation share one definition of "breach."
 *
 * Window is half-open [from, to) on `created_at`, matching RequestVolume:
 * a category's row describes the requests it *received* in the period.
 */
class CategoryPerformance
{
    public function __construct(
        private Team $team,
        private CarbonImmutable $from,
        private CarbonImmutable $to,
    ) {}

    /**
     * Build one row per category.
     *
     * The average is computed in PHP over the in-range requests that actually
     * have a first response — a category with no answered requests has no
     * samples, so its average is null (the view renders "—", never a
     * misleading 0). Categories with no SLA target are present in the table
     * (they still take requests) but their breach count is 0 and target is
     * null — there's nothing to breach.
     *
     * @return Collection<int, CategoryReport>
     */
    public function rows(): Collection
    {
        $categories = Category::query()
            ->where('team_id', $this->team->id)
            ->orderBy('name')
            ->get();

        $rows = [];

        foreach ($categories as $category) {
            $rows[] = $this->row($category);
        }

        return collect($rows);
    }

    private function row(Category $category): CategoryReport
    {
        // Half-open window [from, to): inclusive lower bound, exclusive upper.
        $inRange = Request::query()
            ->where('category_id', $category->id)
            ->where('created_at', '>=', $this->from)
            ->where('created_at', '<', $this->to);

        // Pull the response timestamps once and average in PHP. avg() over an
        // empty collection returns null — exactly the "no samples" signal the
        // view coalesces to "—".
        $responseMinutes = (clone $inRange)
            ->whereNotNull('first_responded_at')
            ->get(['created_at', 'first_responded_at'])
            ->map(fn (Request $request) => $request->created_at->diffInMinutes($request->first_responded_at));

        return new CategoryReport(
            id: $category->id,
            name: $category->name,
            count: (clone $inRange)->count(),
            avgFirstResponseMinutes: $this->average($responseMinutes),
            slaTargetMinutes: $category->sla_first_response_minutes,
            // slaBreached already excludes no-target categories, so a Sales row
            // (no SLA) reports 0 breaches even when piped through the scope.
            breached: (clone $inRange)->slaBreached()->count(),
        );
    }

    /**
     * Mean of the sample minutes, or null when there are no samples.
     *
     * @param  Collection<int, float>  $minutes
     */
    private function average(Collection $minutes): ?float
    {
        if ($minutes->isEmpty()) {
            return null;
        }

        return round((float) $minutes->avg(), 1);
    }
}
