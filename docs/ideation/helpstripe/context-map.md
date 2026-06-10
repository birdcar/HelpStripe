# Context Map: helpstripe

**Phase**: 2
**Scout Confidence**: 89/100
**Verdict**: GO

> Note: Scout ran inline (no Agent subagent tool available in this environment); same workflow, same read-only exploration. Phase 1 sections retained below; Phase 2 findings added.

## Dimensions

| Dimension            | Phase 1 | Phase 2 | Notes (Phase 2)                                                                                                |
| -------------------- | ------- | ------- | -------------------------------------------------------------------------------------------------------------- |
| Scope clarity        | 19/20   | 19/20   | Spec enumerates every new/modified file. One stale reference: pattern `⚡create-team-modal.blade.php` doesn't exist — the create-team modal lives inline in `teams/⚡index.blade.php` (lines 93–112) and `⚡invite-member-modal.blade.php` is the standalone-modal-SFC analogue. |
| Pattern familiarity  | 18/20   | 18/20   | Read `teams/⚡index.blade.php` (SFC page + inline modal + Flux::toast), `teams/⚡edit.blade.php` (mount(Model), Gate::authorize, nested `<livewire:pages::...>` SFCs), `teams/⚡invite-member-modal.blade.php` (standalone modal SFC), `app/Actions/Teams/CreateTeam.php` (handle() + DB::transaction), `app/Notifications/Teams/TeamInvitation.php` (ShouldQueue + toMail/toArray), `app/Policies/TeamPolicy.php`. |
| Dependency awareness | 16/20   | 17/20   | Modified files all additive: `routes/web.php` (`{current_team}` group exists, dashboard only), `sidebar.blade.php` (Queue placeholder at lines 21–24 → repoint), `DemoSeeder.php` (append seed methods), `Request.php` (add helpers). `URL::defaults(['current_team' => slug])` set by `HasTeams::switchTeam` and `EnsureTeamMembership` middleware switches team on mismatch. |
| Edge case coverage   | 16/20   | 17/20   | first_responded_at single-set via `whereNull` guard; self-assign no-notify; cross-team request → 403 via policy; resolved_at cleared when reopened; criteria JSON unknown keys ignored; empty Responses table; pagination page reset when filters change. |
| Test strategy        | 18/20   | 18/20   | `Livewire::test('pages::teams.index')` pattern confirmed in `tests/Feature/Teams/TeamTest.php`; RefreshDatabase global via `tests/Pest.php`; filters: `--filter=QueueTest|ShowRequestTest|ActionsTest|FilterTest`, suite filter `--filter=Requests`. `Event::fake()`/`Notification::fake()` for ActionsTest. |

## Key Patterns

### Phase 2

- `resources/views/pages/teams/⚡index.blade.php` — SFC page anatomy: `new #[Title('…')] class extends Component`, action methods resolve action classes from the container as method args, `#[Computed]` getters, `Flux::toast(variant:, text:)`, `$this->dispatch('close-modal', name: …)`, inline `<flux:modal name="…" :show="$errors->isNotEmpty()">` with `wire:submit` form, `data-test` attributes on interactive elements.
- `resources/views/pages/teams/⚡edit.blade.php` — `mount(Team $team)` route-model-bound page, `Gate::authorize('update', $model)`, nested standalone modal SFCs rendered as `<livewire:pages::teams.invite-member-modal :team="$teamModel" />` with `:key` when in loops, `render()` returning `$this->view()->title(…)`.
- `resources/views/pages/teams/⚡invite-member-modal.blade.php` — standalone modal SFC: public props set in `mount()`, validation in action, `$this->reset(...)`, `dispatch('close-modal')`, toast, the `<flux:modal>` is the root element. This is the analogue for `⚡save-filter-modal` (spec's `⚡create-team-modal` reference is stale).
- `app/Actions/Teams/CreateTeam.php` — action class: single `handle()` method, explicit types, `DB::transaction`, teaching docblock.
- `app/Notifications/Teams/TeamInvitation.php` — `Notification implements ShouldQueue`, `use Queueable`, constructor property promotion, `via()` array, `toMail()` MailMessage chain, `toArray()` for database channel.
- `app/Policies/TeamPolicy.php` — plain policy class, `belongsToTeam()` checks; policies auto-discovered (no manual registration found).
- `tests/Feature/Teams/TeamTest.php` — `Livewire::test('pages::teams.index')->set(...)->call(...)->assertHasErrors/assertSee` style.

### Phase 1 (retained)

- `app/Models/Team.php` — model conventions: `#[Fillable([...])]` attribute (not `$fillable` property), `@property` docblocks for all columns and `@property-read` for relations, `casts()` method (not `$casts` property), typed relation return docblocks (`@return HasMany<Membership, $this>`), `static::creating(...)` in `boot()` for derived attributes.
- `database/factories/TeamFactory.php` — factory conventions: `@extends Factory<Model>` docblock, `fake()` helper, named state methods returning `static`.
- `app/Enums/TeamRole.php` — enum conventions: string-backed, TitleCase cases, `label()` helper, `match($this)` for derived values.
- `tests/Feature/Teams/TeamTest.php` — Pest style: top-level `test('...', function () {...})`, factories, `$this->actingAs(...)`, `assertDatabaseHas`.
- `tests/Pest.php` — `RefreshDatabase` already applied to everything in `tests/Feature`.

## Dependencies

### Phase 2

- `routes/web.php` — `{current_team}` prefix group with `['auth', 'verified', EnsureTeamMembership::class]` already exists; new routes append inside it. `Route::livewire(uri, 'pages::…')` is the established route style (see `routes/settings.php`).
- `resources/views/layouts/app/sidebar.blade.php` — Queue placeholder item (lines 21–24) currently points at `route('dashboard')`; consumed by all authed pages via the app layout. Change is a repoint + badge.
- `database/seeders/DemoSeeder.php` — consumed by `migrate:fresh --seed` and `DemoSeederTest` (asserts exact counts — adding Responses/Filters must not disturb existing counts).
- `app/Models/Request.php` — consumed by Foundation tests, DemoSeeder, factories. Adding scopes/helpers is additive.
- `app/Queries/`, `app/Events/`, `app/Actions/Requests/` — new directories; `app/Actions/Teams/` and `app/Notifications/Teams/` establish the parent-dir precedent (spec explicitly authorizes `app/Queries`).
- `EnsureTeamMembership` resolves the team from `{current_team}` or `{team}` route params and calls `switchTeam` → `URL::defaults(['current_team' => slug])`, so `route('requests.index')` works without explicit slug after middleware runs; tests must pass `['current_team' => $team->slug]` explicitly.

### Phase 1 (retained)

- `app/Models/User.php` — consumed by auth flows, `HasTeams` concern, Fortify actions, Livewire components, `UserFactory`.
- `database/seeders/DatabaseSeeder.php` — uses `WithoutModelEvents`; DemoSeeder must NOT use that trait.

## Conventions

- **Naming**: models singular TitleCase; actions verb-first (`CreateTeam`); notifications `{Thing}Notification` or domain-grouped dirs; tests `{Thing}Test.php` under `tests/Feature/{Domain}/`; SFC pages under `resources/views/pages/{domain}/⚡{name}.blade.php`.
- **Imports**: fully-qualified `use` statements, alphabetized (Pint-enforced); `App\Models\Request` collides with `Illuminate\Http\Request` — alias the framework class (`use Illuminate\Http\Request as HttpRequest;`) where both are needed; inside `App\Models`, bare `Request::class` resolves to the model.
- **Attributes over properties**: `#[Fillable]`, `#[Hidden]` PHP attributes (Laravel 13 starter style).
- **Types**: explicit return types everywhere; generics in docblocks; larastan enforced via `composer test`.
- **Testing**: Pest 4, `test()` functions, feature tests by default, `RefreshDatabase` global for Feature dir, `fake()` helper, `Livewire::test('pages::…')` for SFC pages, `data-test` attributes for DOM assertions.
- **Teaching comments**: this repo explicitly wants teaching docblocks at point of use.
- **Status presentation**: `RequestStatus::color()` returns Flux badge colors — use `<flux:badge :color="$status->color()">`.
- **Activity history**: activitylog v5 — diffs in `Activity::attribute_changes` (`['attributes' => [...], 'old' => [...]]`), logged fields: status, assigned_to, category_id, is_urgent.

## Risks

### Phase 2

- Route param named `{request}` shadows the conventional HTTP request name — fine in Livewire `mount(Request $request)` (model binding via `App\Models\Request` type-hint), but any closure/controller also needing the HTTP request must alias it.
- `DemoSeederTest` asserts exact dataset counts — seeding Responses/Filters must be purely additive; re-check that test after seeder changes.
- Queue page combines `#[Url]` filter props with `WithPagination` (also URL-tracked) — filter changes must call `$this->resetPage()` or stale page numbers return empty pages.
- `RequestAssignedNotification` is `ShouldQueue` — local demo needs `composer run dev` (queue:listen) for mail; database channel rows are written when the queued job is processed. Tour doc demo script must start the queue.
- Saved Filter criteria JSON may carry unknown keys from later phases — `RequestQueue::apply()` must ignore unknown keys (spec failure mode; covered by FilterTest legacy-shape test).
- Activity rows for `assigned_to`/`category_id` store raw IDs — History tab must resolve IDs to names without N+1 (pre-load users/categories maps).

### Phase 1 (retained)

- `App\Models\Request` vs `Illuminate\Http\Request` collision — deliberate; PHPStan catches wrong resolution.
- Spatie permission caches roles/permissions — seeder resets cache before creating permissions.
- Time-dependent demo data: tests freeze time; factory `aged` state takes explicit ranges.
