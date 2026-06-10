# 02 — Ticket Management

This phase ships the agent-facing heart of the product: the request
**queue** (a filterable list) and the request **detail** page (timeline,
reply box, properties panel, history). Under the UI sit four action
classes, four domain events, a queued notification, and a policy — the
seams that Phases 3, 4, 6, and 7 plug into without touching this code
again.

Files to read alongside this doc:

- `routes/web.php` — the two new routes in the `{current_team}` group
- `resources/views/pages/requests/⚡{index,show,save-filter-modal}.blade.php`
- `app/Actions/Requests/{CreateRequest,AddNote,AssignRequest,ChangeStatus}.php`
- `app/Events/{RequestCreated,NoteAdded,RequestAssigned,RequestStatusChanged}.php`
- `app/Notifications/RequestAssignedNotification.php`
- `app/Queries/RequestQueue.php`
- `app/Policies/RequestPolicy.php`
- `app/Models/{Filter,Response}.php`
- `tests/Feature/Requests/`

## 1. Routes: pages are Livewire components

Open `routes/web.php`:

```php
Route::livewire('requests', 'pages::requests.index')->name('requests.index');
Route::livewire('requests/{request}', 'pages::requests.show')->name('requests.show');
```

`Route::livewire()` maps a URL straight to a Livewire component — no
controller. The `pages::` namespace resolves to
`resources/views/pages/`, so `pages::requests.index` is the file
`resources/views/pages/requests/⚡index.blade.php`.

Both live inside the `{current_team}` prefix group, which stacks three
middleware: `auth`, `verified`, and `EnsureTeamMembership`. The
`{request}` parameter route-model-binds to `App\Models\Request` — the
ticket, not `Illuminate\Http\Request`. Same name, different namespace;
the binding works because the `mount()` type-hint says which one.

## 2. Single-file component anatomy

Open `resources/views/pages/requests/⚡index.blade.php`. An SFC is one
file with two halves:

```php
new #[Title('Queue')] class extends Component {
    use WithPagination;

    #[Url]
    public string $status = '';
    // …
}; ?>

<section class="w-full">…</section>
```

- The PHP block declares an anonymous class extending
  `Livewire\Component`. Public properties are the component's reactive
  state; methods are actions the template can call.
- `#[Url]` binds a property to the query string: filter the queue and
  the address bar becomes `?status=active&assignee=me` — shareable,
  bookmarkable, and survives refresh. This attribute is the whole
  saved-Filters design: a Filter is just these criteria persisted.
- `#[Computed]` methods (`requests()`, `categories()`, `staff()`)
  memoize per-render: the template can reference `$this->requests`
  repeatedly and the query runs once.
- `WithPagination` + `->paginate(15)` + `<flux:table :paginate="…">`
  give you pagination with URL tracking for free. Note
  `updated()` calling `resetPage()` — changing a filter while on page 3
  must snap you back to page 1.

## 3. The N+1 lesson

In `requests()` note the eager load:

```php
Request::query()->with(['customer', 'category', 'assignee'])
```

Comment out the `with()` line, run `composer run dev`, load the queue,
and watch Pail: 15 rows now trigger up to 46 queries (1 for the page +
3 per row, one per relation touched in the template). Put it back: 4
queries total. That's the N+1 problem — invisible at 15 rows on SQLite,
lethal at 15,000 on a network database.

## 4. Query objects: one vocabulary, many callers

`app/Queries/RequestQueue.php` is a plain class with one method:
`apply(Builder $builder, array $criteria, ?User $user)`. The component
never builds WHERE clauses; it hands criteria to the query object.

Why the indirection? Three things speak this criteria vocabulary:

1. the queue's filter bar (`#[Url]` properties),
2. saved Filters (`filters.criteria` JSON column),
3. Phase 6's automation conditions and Phase 8's reports, later.

Two design details worth stealing:

- **Unknown keys are ignored.** A Filter saved by a future version with
  keys this code has never heard of still applies its known criteria
  instead of crashing (`FilterTest` covers a "legacy-shaped" JSON blob).
- **`'me'` is symbolic.** A shared "My Open" filter stores
  `assignee: 'me'`, resolved against the *viewer* at apply time — so
  one shared filter means something different (and correct) per agent.

## 5. Action classes: the single write-path

Pattern: `app/Actions/Teams/CreateTeam.php` from the starter. Each
action is one class, one `handle()` method, explicit signature:

| Action | Owns | Fires |
| --- | --- | --- |
| `CreateRequest` | request + opening customer note, in a transaction | `RequestCreated` |
| `AddNote` | timeline entries; `first_responded_at` stamping | `NoteAdded` |
| `AssignRequest` | single-assignee rule; assignment notification | `RequestAssigned` |
| `ChangeStatus` | `resolved_at` lifecycle | `RequestStatusChanged` |

The Livewire components stay thin — validate, call the action, toast.
When Phase 3's email pipeline or Phase 4's portal creates a request,
they call the same `CreateRequest`, and every event listener wired by
then fires for free.

Look at `AddNote` for the concurrency guard:

```php
Request::query()
    ->whereKey($request->id)
    ->whereNull('first_responded_at')
    ->update(['first_responded_at' => now()]);
```

Two agents replying simultaneously race to a conditional UPDATE; the
`whereNull` makes the second one a no-op. The SLA metric (Phase 8 reads
this column) can't be overwritten by a slower second reply.

## 6. Events: plain now, upgraded later

Each event in `app/Events/` is a plain PHP class — `Dispatchable`,
`SerializesModels`, public constructor properties, nothing else. No
`ShouldBroadcast` (Phase 7 adds it), no listeners yet (Phase 6's
trigger engine subscribes).

This is the publisher/subscriber seam: `ActionsTest` proves each action
fires exactly its event with `Event::fake([RequestCreated::class])`.
Note the *explicit event list* — a bare `Event::fake()` would also fake
Eloquent's model events, and `Request::boot()` relies on `creating` to
generate access keys.

`RequestStatusChanged` carries both `$oldStatus` and `$newStatus` so a
future listener can react to a specific transition without re-querying
history.

## 7. Notifications and queues

`app/Notifications/RequestAssignedNotification.php` declares two
channels:

```php
public function via(object $notifiable): array
{
    return ['database', 'mail'];
}
```

- `database` inserts a row into the `notifications` table (migration
  added this phase) — an in-app bell can read it.
- `mail` renders a `MailMessage`; on the local `log` driver it lands in
  `storage/logs/laravel.log` until Phase 3 wires Resend.

It implements `ShouldQueue`, so delivery happens in a queue worker, not
in the request that triggered the assignment. Locally,
`composer run dev` runs `queue:listen` for you — without a worker the
notification just waits in the `jobs` table.

One product rule lives in `AssignRequest`, not the notification:
self-assignment doesn't notify. Grabbing your own ticket shouldn't ping
you.

## 8. Authorization: policy + middleware, different jobs

Two layers guard the detail page, and they answer different questions:

- `EnsureTeamMembership` (middleware): *is the URL's team yours?*
- `RequestPolicy::view` (called via `Gate::authorize` in `mount()`):
  *does this specific request belong to that team?*

Without the policy, any team member could load
`/your-team/requests/{id}` with an id from another team — the
middleware would pass because the URL's team is yours. Trace it in
`ShowRequestTest`: "a request from another team is forbidden" expects
403. The policy is auto-discovered from naming
(`App\Models\Request` → `App\Policies\RequestPolicy`); no registration
anywhere.

## 9. The history tab: activitylog as audit trail

`Request::getActivitylogOptions()` (Phase 1) logs changes to `status`,
`assigned_to`, `category_id`, `is_urgent`. The detail page renders that
log as prose: *"Sam changed status from Active to Resolved · 2h ago"*.

The mapping lives in the ⚡show component's `describeActivity()`:
activitylog v5 stores each diff in `attribute_changes` as
`['attributes' => […new…], 'old' => […old…]]`, with raw foreign-key
ids — the component resolves ids to names and enum values to labels.
The causer (who did it) is captured automatically from the
authenticated user.

## 10. Demo script

Start from a clean seed and a running stack:

```bash
php artisan migrate:fresh --seed
composer run dev   # serve + queue:listen + pail + vite — the queue worker matters for step 7
```

1. **Log in** as `sam@helpstripe.test` / `password`. The sidebar's
   **Queue** badge shows the open-request count (20 seeded).
2. **Filter the queue**: status *Active*, assignee *Unassigned*, toggle
   *Urgent only*. Watch the URL accumulate `?status=…&assignee=…` —
   copy it into a new tab and the view reproduces.
3. **Apply a saved Filter**: open the *Filters* dropdown, pick *Urgent
   Unassigned* (seeded, shared). Same mechanics: criteria in, URL out.
4. **Save a Filter**: set status *Pending*, choose *Save current
   filter…*, name it, flick *Share with the whole team*. It appears in
   the dropdown for every staff member.
5. **Open a request** from the queue. Read the timeline: customer
   messages left-aligned, staff replies blue, private notes amber with
   a lock.
6. **Reply with a canned Response**: in the reply box pick *Need more
   information* from the Response picker — the body drops into the
   textarea. Send. The reply appears at the top of the timeline; if
   this was the request's first staff reply, `first_responded_at` is
   now set (check in tinker).
7. **Add a private note**: switch the segmented control to *Private
   note*, write something candid, save. Amber card, lock icon — Phase
   4's portal will prove customers never see it.
8. **Assign to a teammate**: in Properties, set assignee to *Riley
   Frontline*. Riley gets a database notification row and a mail in
   `storage/logs/laravel.log` (the queue worker delivered both —
   check `php artisan tinker --execute 'App\Models\User::where("email", "riley@helpstripe.test")->first()->notifications;'`).
9. **Resolve it**: set status *Resolved*. Toast confirms; `resolved_at`
   is stamped.
10. **Open the History tab**: every property change you just made is
    there, attributed and humanized, newest first.

## 11. Verify

```bash
php artisan test --compact --filter=Requests   # 4 suites: Queue, ShowRequest, Actions, Filter
./init.sh                                      # lint + static analysis + full suite
```
