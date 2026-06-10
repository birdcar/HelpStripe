<?php

namespace App\Queries\Reports;

use App\Models\Request;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Per-day created-vs-resolved counts for the reporting volume chart.
 *
 * The query-object pattern from App\Queries\RequestQueue: a plain class, a
 * team plus a date window in the constructor, one public method returning
 * data shaped for exactly one consumer (the Flux line/area chart). No
 * Eloquent leaks out — the page never writes a query, it asks this object.
 *
 * The window is half-open: [from, to). `from` is inclusive, `to` exclusive,
 * so a 7-day report ending today covers seven whole days without
 * double-counting the boundary midnight. Every day in the window appears in
 * the result even with zero activity — the chart needs a continuous x-axis,
 * so gaps are zero-filled rather than omitted.
 */
class RequestVolume
{
    public function __construct(
        private Team $team,
        private CarbonImmutable $from,
        private CarbonImmutable $to,
    ) {}

    /**
     * Build the zero-filled per-day series.
     *
     * Strategy (a deliberately *named* simplicity tradeoff — see the tour
     * doc): pull the in-range rows once, then bucket them by day in PHP using
     * each timestamp's date string. This sidesteps SQLite-vs-MySQL date
     * grouping differences (`strftime` vs `DATE()`) at the cost of hydrating
     * rows — fine at demo scale, and the same code path runs on either driver.
     *
     * @return array<string, array{created: int, resolved: int}>
     *                                                           keyed by 'Y-m-d', e.g. ['2026-05-12' => ['created' => 3, 'resolved' => 1]]
     */
    public function perDay(): array
    {
        $series = $this->zeroFilledDays();

        // Half-open window [from, to): inclusive lower bound, exclusive upper.
        $this->bucket(
            Request::query()
                ->where('team_id', $this->team->id)
                ->where('created_at', '>=', $this->from)
                ->where('created_at', '<', $this->to)
                ->pluck('created_at'),
            $series,
            'created',
        );

        $this->bucket(
            Request::query()
                ->where('team_id', $this->team->id)
                ->whereNotNull('resolved_at')
                ->where('resolved_at', '>=', $this->from)
                ->where('resolved_at', '<', $this->to)
                ->pluck('resolved_at'),
            $series,
            'resolved',
        );

        return $series;
    }

    /**
     * Every day in [from, to) seeded with zero counts.
     *
     * CarbonPeriod walks the window one day at a time. The `floorDay()` on
     * `from` and the exclusive `to` give whole calendar days; `->excludeEndDate()`
     * keeps the half-open semantics so the last bucket is the day before `to`.
     *
     * @return array<string, array{created: int, resolved: int}>
     */
    private function zeroFilledDays(): array
    {
        $series = [];

        $period = CarbonPeriod::create(
            $this->from->floorDay(),
            '1 day',
            $this->to->floorDay(),
        )->excludeEndDate();

        foreach ($period as $day) {
            $series[$day->format('Y-m-d')] = ['created' => 0, 'resolved' => 0];
        }

        return $series;
    }

    /**
     * Increment the given metric for each timestamp's day bucket.
     *
     * A timestamp can fall outside the seeded buckets only if it lands on the
     * exclusive end day — the `isset` guard drops those silently rather than
     * inventing a bucket the chart wouldn't draw.
     *
     * @param  Collection<int, CarbonImmutable>  $timestamps
     * @param  array<string, array{created: int, resolved: int}>  $series
     * @param  'created'|'resolved'  $metric
     */
    private function bucket(Collection $timestamps, array &$series, string $metric): void
    {
        foreach ($timestamps as $timestamp) {
            $day = $timestamp->format('Y-m-d');

            if (isset($series[$day])) {
                $series[$day][$metric]++;
            }
        }
    }
}
