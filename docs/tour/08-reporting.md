# 08 — Reporting

The sixth and final pillar. Everything the previous phases recorded —
when a request opened, when it got its first reply, when it was resolved,
who it was assigned to, what SLA its category carries — now pays off as a
single read-only dashboard: **response times, agent performance, and SLA
tracking**, the three things HelpSpot's tour promises a reporting page.

No new tables. No new columns. Reporting is pure aggregation over data
Phases 1–2 already produce. The whole phase is a lesson in turning a
transactional schema into answers: aggregate queries, the query-object
pattern, model scopes as shared business definitions, `CarbonPeriod` for
gap-free time series, and Flux Pro's chart component.

Files to read alongside this doc:

- `app/Models/Request.php` — `scopeSlaBreached()` / `scopeSlaOverdue()`
- `app/Queries/Reports/RequestVolume.php` — per-day created/resolved series
- `app/Queries/Reports/CategoryPerformance.php` — the SLA report
- `app/Queries/Reports/AgentPerformance.php` — per-staff throughput
- `app/Queries/Reports/QueueSnapshot.php` — the stat-card numbers
- `resources/views/pages/reports/⚡index.blade.php` — the page
- `routes/web.php`, `resources/views/layouts/app/sidebar.blade.php` — the gate
- `tests/Feature/Reports/`

## 1. The shape of the page

One page, four blocks, one range selector (7 / 30 / 90 days) scoping all
of them:

1. **Stat cards** — open, unassigned, urgent, SLA-breached, and overdue
   counts. A point-in-time snapshot of the queue *right now*.
2. **Requests over time** — a line/area chart of requests created vs
   resolved, one point per day.
3. **Requests by category** — the SLA report: per category, how many
   requests, the average first-response time, the SLA target, and how many
   breached.
4. **Agent performance** — per staff member: open requests on their plate,
   how many they resolved in the range, and their average first response.

The page component owns **zero query logic**. Each block delegates to a
query object; the Blade just renders shaped arrays. That's the rule the
whole phase is built around.

## 2. The SLA breach — one definition, shared

When does a request *breach* its SLA? You could answer that inline wherever
you need it — but then the reports page and Phase 6's "fire a rule when a
request breaches SLA" automation might compute it differently, and your
dashboard would contradict your automation. The fix is to define it exactly
once, as a model scope:

```php
// app/Models/Request.php
#[Scope]
protected function slaBreached(Builder $query): void { /* … */ }

#[Scope]
protected function slaOverdue(Builder $query): void { /* … */ }
```

A request breaches when **either**:

- it was answered, but the first response landed more than
  `sla_first_response_minutes` after it was created (**answered late**); or
- it's still unanswered and the target window has already elapsed
  (**overdue** — the actionable subset, exposed on its own as
  `scopeSlaOverdue`).

Two deliberate rules, both tested:

- **The boundary is not a breach.** The comparison uses `>`, not `>=`: a
  response that lands at exactly the target minute is in-SLA. (See the
  `exactly on the target minute is not a breach` test.)
- **No target, no breach.** A request whose category has no
  `sla_first_response_minutes` — or no category at all — can never breach;
  there's nothing to measure against. These rows still *count* in the
  category table, they just never contribute to the breach column.

Because both are scopes, the breach math runs **in the database** —
`Request::query()->slaBreached()->count()` never hydrates a row. Phase 6's
automation conditions call the very same scope, so the numbers can't drift.
(That cross-reference runs both ways — see [06-automation.md](06-automation.md).)

### The portable minute-difference

SQLite and MySQL spell "datetime to epoch seconds" differently
(`strftime('%s', …)` vs `UNIX_TIMESTAMP(…)`), so the scope branches on the
connection's driver to build the comparison expression. "Now" is supplied
as a **bound parameter** (Carbon's `now()`), not the database's own clock —
which is why a frozen-time test (`CarbonImmutable::setTestNow(...)`)
measures overdue against the same instant the rest of the app sees, instead
of the wall clock. That bound-vs-DB-clock distinction is the single most
important detail for testable time-based queries.

## 3. Query objects, not fat components

Each block is a class in `app/Queries/Reports/`, following the pattern
`App\Queries\RequestQueue` set in Phase 2: a plain object, a team plus a
date window in the constructor, one public method returning data shaped for
exactly one consumer.

| Class                 | Returns                                                            |
| --------------------- | ----------------------------------------------------------------- |
| `RequestVolume`       | `['2026-05-12' => ['created' => 3, 'resolved' => 1], …]`          |
| `CategoryPerformance` | a row collection: name, count, avg first response, target, breach |
| `AgentPerformance`    | a row collection: name, open assigned, resolved, avg response     |
| `QueueSnapshot`       | `['open' => 20, 'unassigned' => 5, 'urgent' => 2, …]`            |

Why objects instead of methods on the component? Three reasons. They're
**unit-testable without the UI** — build a fixed dataset under a frozen
clock and assert exact numbers, no browser, no Livewire. They're
**reusable** — `QueueSnapshot` could feed a future API or email digest. And
they keep the component **thin** — a `#[Computed]` property per block, each
a one-line delegation.

## 4. Aggregate queries vs collection math

The phase shows both, and *when each wins*:

- **Counts stay in SQL.** `QueueSnapshot` and the category breach count are
  `->count()` calls — the database does the counting, nothing comes back but
  an integer. This is the default; reach for it first.
- **Averages are computed in PHP**, after a small ranged select. Averaging a
  minute-difference in portable SQL means more driver-branching; at demo
  scale (dozens of rows) it's not worth it. So `CategoryPerformance` pulls
  the in-range answered requests and averages their minute-deltas with
  Collection's `avg()`. A **named tradeoff**: hydrating rows to average them
  is fine here and identical on every driver; it would be the wrong call at
  millions of rows, where you'd push a driver-specific aggregate into SQL.

`avg()` over an empty collection returns `null` — which is exactly the
signal we want. A category with no answered requests has no samples, so its
average is `null`, and the view renders **"—"**, never a misleading `0`.
(See `a category with all-unanswered requests reports a null average`.)

## 5. Gap-free time series with CarbonPeriod

A chart needs a continuous x-axis. If you `GROUP BY day` in SQL, days with
zero activity simply don't come back — and the line would skip them,
distorting the shape. `RequestVolume` zero-fills instead:

```php
$period = CarbonPeriod::create($from->floorDay(), '1 day', $to->floorDay())
    ->excludeEndDate();

foreach ($period as $day) {
    $series[$day->format('Y-m-d')] = ['created' => 0, 'resolved' => 0];
}
```

Then it pulls the in-range rows once and increments each day's bucket in
PHP — the same SQLite-safe, group-in-PHP approach, with a continuous
calendar guaranteed up front.

### The window is half-open: `[from, to)`

`from` is **inclusive**, `to` is **exclusive**. A 7-day report covers seven
whole calendar days without double-counting a boundary midnight. A request
created at exactly `from` is in; one at exactly `to` is out. The page sets
`to` to the **start of tomorrow** so today's activity is fully included,
and `from` to `range` whole days before that. Two edge cases worth their
tests:

- A **23:30 request** lands on its own calendar day, not the next — buckets
  are computed in the app timezone consistently, so there's no midnight
  off-by-one. (`a late-night request lands in its own calendar day`.)
- A request **created inside the range but resolved outside it** counts
  toward `created` only — created and resolved are bucketed independently.

## 6. The Flux chart

The volume chart is `<flux:chart>` from Flux Pro, bound to a Livewire
property via `wire:model`:

```blade
<flux:chart wire:model="volume" class="aspect-[3/1]">
    <flux:chart.viewport>
        <flux:chart.svg>
            <flux:chart.line field="created" class="text-blue-500" curve="none" />
            <flux:chart.area field="created" class="text-blue-200/40" curve="none" />
            <flux:chart.line field="resolved" class="text-green-500" curve="none" />
            <flux:chart.axis axis="x" field="date"> … </flux:chart.axis>
            <flux:chart.axis axis="y"> … </flux:chart.axis>
        </flux:chart.svg>
    </flux:chart.viewport>
    <!-- legend … -->
</flux:chart>
```

The bound `volume` property is a **positional list of row arrays** —
`[['date' => '2026-05-12', 'created' => 3, 'resolved' => 1], …]` — which is
why the computed property flattens `RequestVolume`'s date-keyed map into a
list with the date inside each row. Each `<flux:chart.line>` names a `field`;
two lines on one chart gives created-vs-resolved. The x-axis auto-detects the
date scale. Flux handles dark-mode theming, so the chart reads correctly in
both appearances with no extra work. **No query logic touches the view** —
the chart receives a pre-shaped array and nothing else.

## 7. Permission-gated, the spatie way

The route lives in the `{current_team}` group behind `can:view reports` —
the same permission pattern as the KB manager, contrasted in
[02](02-ticket-management.md) and [05](05-knowledge-base.md) with the
*membership* gating the request queue uses:

```php
Route::middleware('can:view reports')->group(function () {
    Route::livewire('reports', 'pages::reports.index')->name('reports.index');
});
```

The sidebar nav item mirrors that middleware with `@can('view reports')`,
so staff without the permission never see a link they'd 403 on. `view
reports` was registered back in Phase 1's `PermissionSeeder` and granted to
the Administrator role — nothing new to seed. The `Help Desk Staff` role
doesn't carry it, so a frontline agent gets a 403 and no nav item.

## 8. The data model — and a measured non-decision

No migration. Reporting rides on the indexes Phase 1 already added —
`requests(team_id, status)` and `requests(assigned_to, status)`. The spec
flagged a possible `requests(created_at)` index for the volume query.
**Measured first: at demo scale (40 rows) it's unnecessary**, so it's
omitted. The lesson is the discipline — add an index when a query's
`EXPLAIN` shows a scan that hurts, not speculatively.

## 9. Empty states and the error table

| Scenario                       | Handling                                        |
| ------------------------------ | ----------------------------------------------- |
| Fresh install, no data         | Every block has a designed empty state; the chart renders axis-only |
| All-unanswered category        | Average is `null` → renders "—", not `0`        |
| Idle agent (no activity)       | A zero row, not an absent one — capacity reads correctly |
| No `view reports` permission   | 403 + hidden nav item                           |

The idle-agent rule is worth dwelling on: `AgentPerformance` is driven by
the **team roster**, not the request table. An agent with no activity gets
a row of zeros. Their absence from the table would read as "no data"; a zero
row reads as "available capacity" — the right story. In the seeded data, the
Administrator (Sam) holds no assigned requests, so that zero row is visible
on the live page.

## 10. Demo script

Seed and serve:

```bash
php artisan migrate:fresh --seed
composer run dev
```

Sign in as `sam@helpstripe.test` (password `password`) — the Administrator,
who has `view reports`.

1. **Open Reports** from the sidebar. The nav item is there because Sam can
   `view reports`; sign in as a `Help Desk Staff` member (e.g.
   `riley@helpstripe.test`) and it's gone — and `/<team>/reports` 403s.
2. **Read the stat cards.** Against the seeded 60-day spread you'll see open
   ≈ 20, some unassigned, a couple urgent, and a non-zero **SLA breached**
   (red) and **Overdue** (amber) count — the seeder alternates first
   responses inside and outside each category's target on purpose.
3. **The volume chart** draws created (blue) vs resolved (green) per day
   across the range. It's continuous — zero days included — so the shape is
   honest.
4. **Requests by category** is the SLA report. Billing (60m target) and
   Technical Support (240m) each show breaches *and* an average; **Sales has
   no target**, so its breach column is `0` and its SLA cell is "—" even
   though it took plenty of requests. That's the no-target exclusion, live.
5. **Agent performance** shows the three frontline staff with real numbers
   and the Administrator as a zero row — the idle-agent case.
6. **Change the range** to 7 days, then 90. Counts move consistently: the
   90-day window is a superset of the 7-day one, so its numbers only grow.
   Watch the URL — `?range=` updates, so the view is shareable.
7. **Watch a number move.** Go to the queue, open a breached request, post a
   public reply (which stamps `first_responded_at`), then return to Reports
   and refresh. If your reply landed within target it's no longer overdue,
   and the Overdue card drops by one — the shared scope in action.

That closes the tour. All six HelpSpot pillars are now reimplemented in
idiomatic Laravel — see [README.md](README.md) for the full map.

## 11. Verify

```bash
php artisan test --compact --filter=Reports   # the six suites below
./init.sh                                      # lint + static analysis + full suite
```

`tests/Feature/Reports/` proves the aggregates with fixed datasets under a
frozen clock, so every expected number is exact:

- **`SlaScopeTest`** — the shared breach definition: late-answered, overdue,
  the boundary (`>` not `>=`), and the no-target / no-category exclusions.
- **`RequestVolumeTest`** — day bucketing, the half-open window edges
  (inclusive `from`, exclusive `to`), zero-filled empty days, and the 23:30
  timezone-boundary fixture.
- **`CategoryPerformanceTest`** — exact averages and breach counts including
  overdue-unanswered; the null average; the present-but-excluded no-SLA row.
- **`AgentPerformanceTest`** — per-agent numbers, the idle zero row,
  unassigned requests excluded, team scoping.
- **`QueueSnapshotTest`** — the five stat-card counts.
- **`ReportsPageTest`** — the permission gate (403 + nav hidden/shown), all
  four blocks rendering, and range switching re-scoping the data (30d ⊇ 7d).
