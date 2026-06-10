# Implementation Spec: HelpStripe - Phase 8: Reporting

**Contract**: ./contract.md
**Estimated Effort**: M

## Technical Approach

One reporting page honoring the tour's "response times, agent performance, and SLA tracking", built from Eloquent aggregates over the Phase 1/2 data (`created_at`, `first_responded_at`, `resolved_at`, `assigned_to`, category SLA targets) and rendered with Flux Pro's chart component. A date-range selector (7/30/90 days) scopes everything.

Four blocks: (1) stat cards — open, unassigned, urgent, SLA-breached counts; (2) **Requests over time** — line/area chart of created vs resolved per day; (3) **Requests by category** — table with counts + avg first-response + SLA target + breach count (the SLA report); (4) **Agent performance** — table per staff member: currently assigned, resolved in range, avg time-to-first-response.

Query logic lives in dedicated query classes (`app/Queries/Reports/*`), the pattern Phase 2 established with `RequestQueue` — each returns plain arrays/collections shaped for its consumer, making them unit-testable without touching the UI. Date bucketing uses database-agnostic patterns that work on SQLite (`strftime`)-vs-MySQL differences are avoided by grouping in PHP after a single ranged select where row counts allow (demo scale) — a deliberate, *named* simplicity tradeoff taught in the tour doc.

SLA semantics (established Phase 1/2): a request breaches when `first_responded_at - created_at` exceeds its category's `sla_first_response_minutes`, or when unanswered and `now - created_at` exceeds it (overdue). Exposed as `Request::scopeSlaBreached()` so Phase 6 automation conditions and these reports share one definition.

## Feedback Strategy

**Inner-loop command**: `php artisan test --compact --filter=Reports`

**Playground**: Pest tests on query classes with factory-built fixed datasets (frozen clock); browser at `/{team}/reports` against the seeder's 60-day spread for visual checks.

**Why this approach**: Aggregates are pure logic — deterministic datasets + frozen time give exact expected numbers; the chart layer is thin.

## File Changes

### New Files

| File Path | Purpose |
| --- | --- |
| `app/Queries/Reports/RequestVolume.php` | Per-day created/resolved counts for the range |
| `app/Queries/Reports/CategoryPerformance.php` | Per-category: count, avg first response (mins), SLA target, breached count |
| `app/Queries/Reports/AgentPerformance.php` | Per-staff: open assigned, resolved in range, avg first response |
| `app/Queries/Reports/QueueSnapshot.php` | Stat-card numbers (open/unassigned/urgent/breached) |
| `resources/views/pages/reports/⚡index.blade.php` | Reports page: range select, cards, chart, two tables |
| `tests/Feature/Reports/RequestVolumeTest.php` | Day bucketing, range edges, empty days zero-filled |
| `tests/Feature/Reports/CategoryPerformanceTest.php` | Averages + breach counts incl. overdue-unanswered |
| `tests/Feature/Reports/AgentPerformanceTest.php` | Per-agent numbers; unassigned excluded |
| `tests/Feature/Reports/ReportsPageTest.php` | Page renders, permission gate, range switching |
| `docs/tour/08-reporting.md` | Tour doc: aggregates, scopes, query objects, Flux charts + demo script |

### Modified Files

| File Path | Changes |
| --- | --- |
| `app/Models/Request.php` | `scopeSlaBreached()` / `scopeSlaOverdue()` (shared definition) |
| `routes/web.php` | `reports` route behind `can:view reports` in the `{current_team}` group |
| `resources/views/layouts/app/sidebar.blade.php` | "Reports" nav item (permission-gated) |
| `database/seeders/DemoSeeder.php` | Verify spread produces non-degenerate charts (some breaches, some in-SLA, all agents represented) — adjust factory weights if needed |

## Implementation Details

### Query classes

**Pattern to follow**: `app/Queries/RequestQueue.php` (Phase 2)

**Overview**: Each class: constructor takes team + `CarbonImmutable $from/$to`; one public method returning shaped data. `RequestVolume` returns `['2026-05-12' => ['created' => 3, 'resolved' => 1], ...]` zero-filled for every day in range. `CategoryPerformance`/`AgentPerformance` return row collections matching their table columns. Averages computed with `avg()` on a derived minutes expression where trivially portable, else collection math after a ranged select — keep each class internally consistent and tested.

**Key decisions**:
- `first_responded_at` (set by Phase 2's AddNote) is the single source for response-time math — no recomputation from notes.
- Overdue-unanswered counts as breached in category tables (it's actionable), shown as its own stat card too.
- Zero-filling in PHP via `CarbonPeriod` — teaches period iteration; avoids DB-specific calendar tricks.

**Feedback loop**:
- **Playground**: per-class Pest test with `Carbon::setTestNow()` and hand-built requests (e.g., 3 requests Monday: responded in 30m/90m against 60m target → 1 breach, avg 60m).
- **Experiment**: exact expected numbers; boundary request exactly at target (not breached — `>` not `>=`, documented); empty range; category with no SLA target excluded from breach math but present in counts.
- **Check command**: `php artisan test --compact --filter=CategoryPerformanceTest` (and siblings)

### Reports page (⚡index)

**Pattern to follow**: `resources/views/pages/requests/⚡index.blade.php`; flux:chart per Flux Pro docs (Boost `search-docs` `['chart']` scoped to fluxui packages — verify current `<flux:chart>` API before wiring)

**Overview**: `#[Url]` range property (`7|30|90`), computed properties delegating to the query classes, stat cards as flux cards, the volume chart as `<flux:chart>` line/area fed the zero-filled series, two flux:table blocks. Breached rows get a danger badge; SLA column shows target vs actual avg.

**Key decisions**:
- Computed properties cache per-request render; no extra caching layer (demo scale, named).
- Chart receives pre-shaped arrays from RequestVolume — no query logic in the view layer.

**Implementation steps**:
1. Route + permission + skeleton with stat cards.
2. Volume chart wired to RequestVolume.
3. Category + agent tables.
4. Range switching + URL binding.

**Feedback loop**:
- **Playground**: browser against seeded data; `ReportsPageTest` smoke first.
- **Experiment**: switch ranges → numbers change consistently (30d ⊇ 7d counts); staff without `view reports` → 403 + no nav item; seeded data renders all four blocks non-empty.
- **Check command**: `php artisan test --compact --filter=ReportsPageTest`

### Tour doc 08

Covers: aggregate queries vs collection math (when each wins), query objects, scopes as shared business definitions (SLA), `CarbonPeriod`, Flux charts, permission-gated routes. Demo script: open Reports as admin, walk each block against known seeded facts, change range, then resolve a breached request in the queue and watch the numbers move on refresh. Closes the tour: README updated marking all six pillars complete.

## Data Model

No new tables. Relies on indexes from Phase 1 (`requests(team_id, status)`, `requests(assigned_to, status)`); add `requests(created_at)` index in a small migration if the seeded-scale queries warrant it (measure first — likely unnecessary, note in doc).

## Testing Requirements

Per component above. **Key edge cases**: request created inside range but resolved outside (counts created-only); agent with zero activity renders zero row (not absent); all-unanswered category avg shows "—" not 0; boundary timestamps at range edges (inclusive from, exclusive to — documented).

### Manual Testing

- [ ] Demo script start-to-finish
- [ ] Charts render in dark mode (Flux handles theming — verify)

## Error Handling

| Error Scenario | Handling Strategy |
| --- | --- |
| Empty dataset (fresh install, no seed) | Every block has a designed empty state; charts render axis-only |
| Division by zero in averages | Guard: null avg renders "—" |
| No permission | 403 + hidden nav |

## Failure Modes

| Component | Failure Mode | Trigger | Impact | Mitigation |
| --- | --- | --- | --- | --- |
| Volume query | Timezone bucketing drift | UTC storage vs local display day | counts off-by-one at midnight | bucket in app timezone consistently; test with a 23:30 UTC fixture |
| SLA scope | Definition drift | reports and automation computing breach differently | contradictory numbers | single `scopeSlaBreached` shared by both; cross-referenced in both tour docs |
| Agent table | Deleted/removed staff | assignee left team | row with stale user | include with "(former staff)" label via withTrashed-style guard — users aren't soft-deleted, so just left-join tolerance |
| Chart | Large range payload | 90d × dense data | sluggish render | demo scale fine; named limit |

## Validation Commands

```bash
composer lint
php artisan test --compact --filter=Reports
composer test
./init.sh
```

## Rollout Considerations

None. This is the final phase: update `feature_list.json` (all phases done), `progress.md`, and `session-handoff.md`; confirm `docs/tour/README.md` indexes all eight docs.

## Open Items

- [ ] Verify current `<flux:chart>` component API/props via Boost `search-docs` before building the volume chart.
