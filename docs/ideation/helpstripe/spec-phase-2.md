# Implementation Spec: HelpStripe - Phase 2: Ticket Management

**Contract**: ./contract.md
**Estimated Effort**: L

## Technical Approach

This phase ships the agent-facing heart of the product: the request queue (list) and request detail pages as Livewire 4 single-file components, plus the action classes, events, and notifications that later phases hook into. It implements the tour's "single assignment, full history" claims: one assignee per request, every change logged via activitylog and rendered as a History tab.

The write-path goes through action classes (`app/Actions/Requests/*`) following the starter's `app/Actions/Teams/CreateTeam.php` pattern — Livewire components stay thin and the actions become the single place Phase 3 (email), Phase 4 (portal), and Phase 6 (automation) reuse. Each action fires a domain event (`RequestCreated`, `NoteAdded`, `RequestAssigned`, `RequestStatusChanged`); Phase 6's trigger engine and Phase 7's broadcasting subscribe to these without touching this phase's code again.

Saved **Filters** (HelpSpot's name for saved views) are a `Filter` model holding criteria JSON; the queue component applies them via a dedicated query builder method. **Responses** (HelpSpot's canned replies) are a small model + picker in the reply box.

## Feedback Strategy

**Inner-loop command**: `php artisan test --compact --filter=Requests`

**Playground**: Dev server (Herd serves the app; run `composer run dev` for queue/vite/pail) seeded via `migrate:fresh --seed`, plus Pest Livewire tests for logic.

**Why this approach**: UI-heavy phase — the browser against seeded data is the primary loop; Livewire component tests keep the logic loop fast.

## File Changes

### New Files

| File Path | Purpose |
| --- | --- |
| `resources/views/pages/requests/⚡index.blade.php` | Request queue: filterable list |
| `resources/views/pages/requests/⚡show.blade.php` | Request detail: timeline, reply box, properties panel, history tab |
| `resources/views/pages/requests/⚡save-filter-modal.blade.php` | Save current criteria as a named Filter |
| `app/Models/Filter.php` + migration + factory | Saved views: name, user_id, is_shared, criteria JSON |
| `app/Models/Response.php` + migration + factory | Canned replies: name, body |
| `app/Actions/Requests/CreateRequest.php` | Create request + initial customer note; fires RequestCreated |
| `app/Actions/Requests/AddNote.php` | Add public reply / private note; sets first_responded_at; fires NoteAdded |
| `app/Actions/Requests/AssignRequest.php` | Assign/unassign; fires RequestAssigned |
| `app/Actions/Requests/ChangeStatus.php` | Status transitions; sets resolved_at; fires RequestStatusChanged |
| `app/Events/RequestCreated.php` | Domain event (plain, broadcast added in Phase 7) |
| `app/Events/NoteAdded.php` | Domain event |
| `app/Events/RequestAssigned.php` | Domain event |
| `app/Events/RequestStatusChanged.php` | Domain event (carries old + new status) |
| `app/Notifications/RequestAssignedNotification.php` | Database + mail notification to the new assignee |
| `app/Queries/RequestQueue.php` | Builds the queue query from criteria (status/category/assignee/urgent/search) |
| `app/Policies/RequestPolicy.php` | Team-membership-based access |
| `tests/Feature/Requests/QueueTest.php` | Queue rendering + filtering |
| `tests/Feature/Requests/ShowRequestTest.php` | Detail page, reply/note, assignment, status |
| `tests/Feature/Requests/ActionsTest.php` | Action classes incl. events fired, first_responded_at |
| `tests/Feature/Requests/FilterTest.php` | Saved filters apply correctly |
| `docs/tour/02-ticket-management.md` | Tour doc: Livewire SFCs, actions, events, notifications, policies + demo script |

### Modified Files

| File Path | Changes |
| --- | --- |
| `routes/web.php` | In the `{current_team}` group: `Route::livewire('requests', 'pages::requests.index')->name('requests.index')` and `requests/{request}` → `requests.show` |
| `resources/views/layouts/app/sidebar.blade.php` | Point Queue item at `requests.index`; badge with open-count |
| `database/seeders/DemoSeeder.php` | Seed 3 Responses, 2 shared Filters ("My Open", "Urgent Unassigned") |
| `app/Models/Request.php` | Add `scopeForCriteria` hook or keep in RequestQueue; add `notes()` ordering helper |

## Implementation Details

### Request queue (⚡index)

**Pattern to follow**: `resources/views/pages/teams/⚡index.blade.php` (SFC structure, Flux usage, `#[Computed]`)

**Overview**: Paginated table (flux:table) with columns: number, subject (+urgent badge), customer, category, assignee, status badge, updated_at. Filter bar: status select, category select, assignee select ("Me", "Unassigned", staff), urgent toggle, text search (subject + customer email LIKE). A Filter dropdown applies saved Filters; "Save filter" opens the modal.

**Key decisions**:
- Criteria live as URL-bound Livewire properties (`#[Url]`) so views are shareable/bookmarkable — this is also how a saved Filter is just stored criteria.
- Query building lives in `app/Queries/RequestQueue.php` (plain class, `apply(Builder, array $criteria)`) so Phase 6's automation conditions and Phase 8's reports can reference the same vocabulary. Teaches dedicated query objects.
- Eager-load `customer`, `category`, `assignee` — the tour doc shows the N+1 before/after with Pail/debugbar as the lesson.

**Implementation steps**:
1. Route + skeleton SFC rendering seeded requests.
2. RequestQueue criteria application + tests.
3. Filter bar wiring (`#[Url]` properties → criteria array).
4. Saved Filter apply + save modal (pattern: `⚡create-team-modal.blade.php`).
5. Pagination, empty state, open-count sidebar badge.

**Feedback loop**:
- **Playground**: browser at `/{team}/requests` with seeded data; `tests/Feature/Requests/QueueTest.php` smoke test first.
- **Experiment**: filter by each criterion alone and combined; "Unassigned" + urgent returns the seeded subset; search matches subject substring; pagination at >15 rows.
- **Check command**: `php artisan test --compact --filter=QueueTest`

### Request detail (⚡show)

**Pattern to follow**: `resources/views/pages/teams/⚡edit.blade.php` for page + nested SFC composition

**Overview**: Three regions. (1) Timeline: notes newest-first; customer notes left-aligned, staff public replies accent-styled, private notes visually distinct (amber background + lock icon). (2) Reply box: tabs "Public reply" / "Private note", textarea, Response (canned reply) picker that inserts body text, submit via `AddNote`. (3) Properties sidebar: status select, assignee select, category select, urgent toggle, tags input, customer card (name, email, link-list of their other requests). History tab renders `$request->activities` (activitylog) as "Sam changed status from Active to Resolved · 2h ago".

**Key decisions**:
- Route model binding on `{request}` with team scoping enforced via `RequestPolicy` + `EnsureTeamMembership` (pattern: existing middleware) — the tour doc traces an authorization failure on a cross-team request ID.
- Property changes call the actions directly (no "edit mode") — optimistic, toast on success (`Flux::toast`, pattern in ⚡index of teams).
- `AddNote` sets `first_responded_at` only for the first staff **public** note — the data Phase 8's SLA report consumes.

**Implementation steps**:
1. Route + binding + policy; render timeline read-only.
2. Reply box with public/private tabs → `AddNote`.
3. Properties panel selects → actions; toasts.
4. Response picker (simple flux:select inserting body into textarea via Alpine).
5. History tab from activitylog.
6. Tags via flux input + spatie `syncTags`.

**Feedback loop**:
- **Playground**: browser on a seeded request; `ShowRequestTest` smoke first.
- **Experiment**: add public reply → first_responded_at set once (second reply doesn't move it); private note → no first_responded_at; assign → notification queued; resolve → resolved_at set; cross-team request ID → 403/404.
- **Check command**: `php artisan test --compact --filter=ShowRequestTest`

### Actions + events + notification

**Pattern to follow**: `app/Actions/Teams/CreateTeam.php`

**Overview**: Four small invokable-style classes (`handle(...)`), each firing its event. `RequestAssignedNotification` (database + mail channels, `ShouldQueue`) notifies the assignee with a link; mail renders fine on the `log` driver until Phase 3 wires Resend.

**Key decisions**:
- Events are plain in this phase (no `ShouldBroadcast`) — Phase 7 upgrades them. Listeners-by-discovery is the lesson hook for Phase 6.
- Self-assignment doesn't notify (guard in `AssignRequest`).

**Feedback loop**:
- **Playground**: `ActionsTest` with `Event::fake()` / `Notification::fake()`.
- **Experiment**: each action fires exactly its event with right payload; assignment notifies assignee but not self-assignment; status change to Resolved sets resolved_at, back to Active clears it.
- **Check command**: `php artisan test --compact --filter=ActionsTest`

### Tour doc 02

Walks: route → SFC anatomy (the `new class extends Component` header, properties, actions, computed) → action classes → events/`Event::fake` → notifications/queues → policy authorization. Demo script: seed fresh, log in as staff, filter the queue, save a Filter, open a request, reply with a canned Response, add private note, assign to teammate (show notification bell), resolve, view History tab.

## Data Model

```php
// filters
$table->id();
$table->foreignId('team_id')->constrained();
$table->foreignId('user_id')->constrained();   // owner
$table->string('name');
$table->boolean('is_shared')->default(false);
$table->json('criteria');                      // {status, category_id, assignee, urgent, search}
$table->timestamps();

// responses
$table->id();
$table->foreignId('team_id')->constrained();
$table->string('name');
$table->text('body');
$table->timestamps();
```

## Testing Requirements

| Test File | Coverage |
| --- | --- |
| `QueueTest` | Renders seeded rows; each filter criterion; combined criteria; search; pagination; guests redirected |
| `ShowRequestTest` | Timeline renders public+private; reply flows; property changes; history entries; cross-team 403 |
| `ActionsTest` | Events fired; first_responded_at/resolved_at semantics; notification fan-out |
| `FilterTest` | Saved criteria round-trip; shared filters visible to teammates; private ones aren't |

**Key edge cases**: request with zero staff notes; unassigned request assignment from null; status enum invalid value rejected by validation; Response picker with empty responses table.

### Manual Testing

- [ ] Demo script in `docs/tour/02-ticket-management.md` runs start-to-finish
- [ ] Notification appears for assignee (database channel) and in `storage/logs` (mail on log driver)

## Error Handling

| Error Scenario | Handling Strategy |
| --- | --- |
| Validation failure on reply (empty body) | Livewire validation errors inline (`$this->validate`) |
| Request not in current team | Policy denies → 403 via route binding |
| Stale UI (request changed elsewhere) | Accept for now — Phase 7 adds live updates; named here so it's a known gap |

## Failure Modes

| Component | Failure Mode | Trigger | Impact | Mitigation |
| --- | --- | --- | --- | --- |
| Queue query | N+1 explosion | missing eager loads | slow page | eager-load + tour doc lesson; Larastan/`Model::shouldBeStrict()` optional note |
| Saved Filter | Criteria drift | criteria JSON schema changes later | filter applies wrong/no constraints | RequestQueue ignores unknown keys; test covers legacy-shaped JSON |
| AddNote | first response double-set | concurrent replies | wrong SLA metric | `whereNull('first_responded_at')`-guarded update |
| Assignment notification | queue not running locally | demo without `composer run dev` | "missing" notifications | Tour doc demo script starts the queue listener; notification also on database channel (visible without queue flush since queue:listen processes both) |

## Validation Commands

```bash
composer lint
php artisan test --compact --filter=Requests
composer test
./init.sh
```

## Rollout Considerations

None. Update `feature_list.json` + `progress.md` with evidence on completion.
