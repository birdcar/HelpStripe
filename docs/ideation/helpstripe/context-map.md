# Context Map: helpstripe

**Phase**: 7
**Scout Confidence**: 88/100
**Verdict**: GO

> Note: Scout ran inline (no Agent subagent tool available in this environment); same workflow, same read-only exploration. Phase 1–2–3–5 sections retained below; Phase 7 findings added. (feat-007 Collision Detection depends only on feat-002, which is done.)

## Dimensions

| Dimension            | Phase 1 | Phase 2 | Phase 5 | Phase 3 | Phase 7 | Notes (Phase 7)                                                                                                |
| -------------------- | ------- | ------- | ------- | ------- | ------- | -------------------------------------------------------------------------------------------------------------- |
| Scope clarity        | 19/20   | 19/20   | 18/20   | 18/20   | 18/20   | Spec enumerates every file. Open Item resolved: Livewire 4 echo listeners with an embedded channel variable (`request.{id}`) must be registered via `getListeners()` returning `"echo-presence:request.{$this->helpdeskRequest->id},here" => 'method'` (and `joining`/`leaving`/`NoteAdded`) — the static `#[On('echo-presence:request.{id},…')]` attribute form is for class-property interpolation; getListeners is the robust path for the bound `$helpdeskRequest`. Broadcasting is NOT yet installed (no config/broadcasting.php, no reverb in composer.lock, BROADCAST_CONNECTION=log) → `install:broadcasting --reverb` runs clean. |
| Pattern familiarity  | 18/20   | 18/20   | 18/20   | 18/20   | 17/20   | Read ⚡show SFC (mount route-model binds `Request $request` → `$helpdeskRequest`; Gate::authorize('view') in mount; `#[Computed] notes()` = the timeline to refresh), NoteAdded event (plain, Dispatchable+SerializesModels — gains ShouldBroadcast), AddNote action (dispatches NoteAdded after create; `toOthers()` needs the socket id, set client-side by Echo), HasTeams::belongsToTeam(Team), Team::members(), Note model. First broadcast channel, first ShouldBroadcast event, first Echo/JS wiring in the repo — Laravel/Livewire conventions apply. app.js is EMPTY (0 bytes) — `import './echo'` is the first line. |
| Dependency awareness | 16/20   | 17/20   | 18/20   | 17/20   | 18/20   | NoteAdded already has one listener (SendPublicReplyEmail, queued, NoteAdded→public staff reply). Adding ShouldBroadcast is additive — listener unaffected. ⚡show is consumed by ShowRequestTest (Livewire::test('pages::requests.show')); adding getListeners()/viewer state must not break existing assertions. routes/channels.php is NEW — bootstrap/app.php withRouting() has no `channels:` key yet; install:broadcasting wires it. BROADCAST_CONNECTION currently `log` — tests run with broadcasting effectively inert unless faked; use Event::fake / Broadcast assertions. |
| Edge case coverage   | 16/20   | 17/20   | 17/20   | 18/20   | 17/20   | From spec: member→array payload, non-member→false, guest→denied (guard rejects before closure). Two tabs = one user (dedupe by id in $viewers). `toOthers()` so author doesn't double-refresh. broadcastWith sends note_id ONLY (no body on the wire — private-note leak guard). Request deleted while channel open → auth closure 404s gracefully (route-model bind on /broadcasting/auth). Reverb absent → Echo connect fails silently, page still works (no hard dep). |
| Test strategy        | 18/20   | 19/20   | 18/20   | 19/20   | 18/20   | Pest feature tests under tests/Feature/Collision/. ChannelAuthTest: POST /broadcasting/auth (or `Broadcast::channel` closure invoked directly) — member 200 w/ channel_data, foreign-team user denied, guest denied. BroadcastTest: NoteAdded implements ShouldBroadcast; `broadcastOn()` returns PresenceChannel('request.{id}'); `broadcastWith()` = `['note_id'=>…]` only; Event::fake + assertDispatched / ShouldBroadcast contract. Live two-browser behavior is the manual matrix (tour doc). |

## Key Patterns

### Phase 7

- `resources/views/pages/requests/⚡show.blade.php` — the SFC to modify. `mount(Request $request)` binds the ticket to `public Request $helpdeskRequest` after `Gate::authorize('view', $request)`. `#[Computed] notes()` returns `$helpdeskRequest->timeline()->get()` — the timeline to refresh on a remote `NoteAdded` (`unset($this->notes)` then `$refresh`, mirroring how `addNote()` already does `unset($this->notes)`). Echo listeners attach via `getListeners()` (channel name embeds `$this->helpdeskRequest->id`). Viewer state is a public array prop mutated by here/joining/leaving handlers.
- `app/Events/NoteAdded.php` — plain event `__construct(public Note $note)`. Gains `implements ShouldBroadcast`, `use InteractsWithSockets`, `broadcastOn(): PresenceChannel` (`new PresenceChannel('request.'.$this->note->request_id)`), `broadcastWith(): array` = `['note_id' => $this->note->id]` ONLY, optional `broadcastWhen()`. `toOthers()` is invoked at dispatch site (or via InteractsWithSockets + the socket id Echo sets) — author's own page must NOT refresh.
- `app/Actions/Requests/AddNote.php` — `NoteAdded::dispatch($note)` is the single broadcast trigger. To exclude the author's own client, dispatch via `broadcast(new NoteAdded($note))->toOthers()` OR keep `NoteAdded::dispatch` and rely on the socket-id header. Note: the queued `SendPublicReplyEmail` listener already consumes NoteAdded — unaffected by ShouldBroadcast.
- `app/Concerns/HasTeams.php::belongsToTeam(Team $team): bool` + `Team::members()` — channel auth closure mirror of RequestPolicy: `$user->belongsToTeam($helpdeskRequest->team)`. Returns `['id'=>…, 'name'=>…]` (User has NO avatar/profile_photo column — avatar stack uses `<flux:avatar :name="…">` initials, same as the timeline already does).
- Livewire 4 echo listeners (Boost-verified 2026-06-10): `getListeners()` returns `["echo-presence:request.{$id},here" => 'syncHere', "echo-presence:request.{$id},joining" => 'addViewer', "echo-presence:request.{$id},leaving" => 'removeViewer', "echo-presence:request.{$id},NoteAdded" => 'refreshTimeline']`. `here` handler receives the full member array; joining/leaving receive a single member. Class-name event (NoteAdded) — no leading dot needed (that's only for `broadcastAs()` custom names).
- Reverb/Echo install (Boost-verified): `php artisan install:broadcasting --reverb` publishes config/broadcasting.php + config/reverb.php, adds REVERB_*/VITE_REVERB_* to .env(.example), wires `channels:` routing, and scaffolds resources/js/echo.js + installs laravel-echo/pusher-js. In this repo app.js is empty and bun is the pacakge manager (package.json has no echo deps yet) — verify the scaffold; may need `bun add laravel-echo pusher-js` + manual `import './echo'` in app.js.

### Phase 3

- `app/Actions/Requests/CreateRequest.php` — the single write-path for new requests: DB::transaction wrapping request + opening customer note, event dispatched after commit. Email/API channels call it with `source` + `$attributes` (gains `message_id` for the opening note).
- `app/Actions/Requests/AddNote.php` — `handle(Request, User|Customer $author, string $body, bool $isPrivate, RequestSource $source)`; customer replies pass Customer author + RequestSource::Email. Gains optional `$messageId` + `$attachments` (downloaded temp files).
- `app/Actions/Requests/ChangeStatus.php` — owns resolved_at lifecycle; the inbound reopen path MUST go through it (clears resolved_at, fires RequestStatusChanged).
- `app/Notifications/RequestAssignedNotification.php` — queued mail pattern: `implements ShouldQueue`, `use Queueable`, constructor promotion, teaching docblocks.
- Resend ground truth (verified 2026-06-10 against resend.com/docs): webhook `email.received` payload = `{type, created_at, data: {email_id, from, to[], cc, bcc, subject, message_id, attachments[meta]}}`; body/headers via `GET https://api.resend.com/emails/receiving/{id}` (`{html, text, headers{lowercased}, message_id, attachments[]}`); attachment binaries via `GET .../receiving/{id}/attachments` (`data[].{download_url, size, filename, content_type}`). Signature: svix headers (`svix-id`, `svix-timestamp`, `svix-signature` as space-separated `v1,<base64>` list), secret `whsec_<base64>`, HMAC-SHA256 over `"{id}.{timestamp}.{rawBody}"`.
- Laravel mail Headers: `new Headers(messageId: 'note-1@domain', references: ['prior@domain'])` — IDs without angle brackets; Symfony adds them. Inbound Resend message_ids arrive WITH brackets — normalize (strip `<>`) at the DTO.
- spatie/laravel-webhook-client: config `webhook-client.php` configs[] entry (name, signing_secret, signature_validator, webhook_profile, webhook_response, webhook_model, process_webhook_job); `Route::webhooks('webhooks/resend', 'resend')`; job extends `ProcessWebhookJob` with `$this->webhookCall->payload`. CSRF exemption via `$middleware->validateCsrfTokens(except:)` in bootstrap/app.php.
- spatie/laravel-medialibrary: model `implements HasMedia` + `use InteractsWithMedia`; `$note->addMedia($tmpPath)->usingFileName(...)->toMediaCollection('attachments')`; publish `create_media_table` migration.

### Phase 5

- `app/Models/Category.php` + `database/migrations/...create_categories_table.php` + `CategoryFactory` — the team-scoped model triple to replicate for KnowledgeBook: `#[Fillable]` attribute, `@property` docblocks, typed relation docblocks, teaching docblocks in migrations, factory with `fake()` + named states.
- `resources/views/pages/teams/⚡index.blade.php` — page SFC anatomy: `new #[Title] class extends Component`, `#[Computed]`, action methods resolving deps from container, `Flux::toast`, inline `<flux:modal name=… :show="$errors->isNotEmpty()">` + `flux:modal.trigger`, `data-test` attributes.
- `resources/views/pages/requests/⚡show.blade.php` — `mount(Model $param)` route-model binding + authorization in mount.
- `resources/views/layouts/app.blade.php` + `partials/head.blade.php` — layout component anatomy; portal layout mirrors this minus sidebar (`<x-layouts::…>` not needed: plain Blade layout with `{{ $slot }}`, `@fluxAppearance`/`@fluxScripts`, title via `$title`).
- `database/seeders/PermissionSeeder.php` — `manage knowledge base` permission + Administrator role already exist; `can:manage knowledge base` route middleware works through the Gate (spatie registers permissions as abilities).
- Livewire 4: portal pages opt out of the app layout with `#[Layout('layouts::portal')]`; default is `layouts::app`.
- Laravel 13 scopes: `#[Scope] protected function published(Builder $query): void` — first scopes in the app, establish the attribute style.
- Sluggable: `use HasSlug; getSlugOptions(): SlugOptions` → `SlugOptions::create()->generateSlugsFrom('name')->saveSlugsTo('slug')->extraScope(fn ($builder) => $builder->where('chapter_id', $this->chapter_id))`. Default behavior regenerates slug on source rename (spec wants this — old slug 404s).

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

### Phase 7

- `app/Events/NoteAdded.php` — consumed by `SendPublicReplyEmail` listener (queued, fires on public staff reply). Adding `ShouldBroadcast` is additive: the listener path is unchanged; broadcasting is a parallel dispatch. With `BROADCAST_CONNECTION=log` (test default) the broadcast no-ops to the log channel — existing tests that add notes are unaffected unless they `Event::fake()` and then must account for NoteAdded being broadcastable.
- `app/Actions/Requests/AddNote.php` — consumed by ⚡show `addNote()`, ProcessInboundEmail (Phase 3), CreateRequest's opening note path, ActionsTest/ShowRequestTest/Inbox tests. If `toOthers()` is added at the dispatch site, it requires a current socket connection; in non-HTTP/queued contexts `toOthers()` is a safe no-op (no socket id) — broadcasts to everyone, which is correct for inbound email replies.
- `resources/views/pages/requests/⚡show.blade.php` — consumed by ShowRequestTest (`Livewire::test('pages::requests.show', ['request' => $request])`). New public `$viewers` prop + `getListeners()` + refresh handlers must not disturb existing mount/addNote/property-update assertions. The new `⚡viewers.blade.php` partial is rendered inside show.
- `resources/js/app.js` (empty) + `vite.config.js` (inputs list) — `import './echo'` added; echo.js is a new Vite-bundled entry (already in the app.js input, no vite.config change needed if imported from app.js). `bun run build` must regenerate the manifest or ViteException.
- `bootstrap/app.php` — `withRouting()` currently has web/api/commands/health; `install:broadcasting` adds `channels: __DIR__.'/../routes/channels.php'`. Verify it lands.
- `composer.json` `dev` script — concurrently stack (server, queue, logs, vite) gains a `reverb` process: `php artisan reverb:start`.

### Phase 3

- `app/Actions/Requests/AddNote.php` — consumed by `pages/requests/⚡show.blade.php` (reply box) and ActionsTest/ShowRequestTest; new params must be optional with defaults so existing call sites compile unchanged.
- `app/Events/NoteAdded.php` — gains its first listener (SendPublicReplyEmail). Every existing test that adds a public staff note will now render PublicReplyMail to the array transport inline (sync queue) — the mailable must tolerate `mailbox_id = null` (fall back to `config('mail.from')`).
- `database/seeders/DemoSeeder.php` — DemoSeederTest asserts `support@helpstripe.test` / `billing@helpstripe.test`; address derivation from `config('helpstripe.inbound_domain')` must default to `helpstripe.test`.
- `bootstrap/app.php` — gains `api:` routing (first API route) + CSRF exemption for `webhooks/*`.
- `composer.json` — +resend/resend-php, +spatie/laravel-webhook-client, +spatie/laravel-medialibrary (spec-approved).

### Phase 5

- `routes/web.php` — admin kb routes go inside the existing `{current_team}` group with extra `can:manage knowledge base` middleware; portal kb routes are a new top-level public group (`Route::prefix('portal')->name('portal.')`) — Phase 4 will add its own routes to a sibling/merged group later. `Route::has('portal.kb.index')` is the cross-phase guard Phase 4 consumes.
- `resources/views/layouts/portal.blade.php` — created here (minimal); Phase 4 spec lists it as its own new file → Phase 4 should treat it as existing and extend, not recreate (noted in implementation notes).
- `database/seeders/DemoSeeder.php` — `DemoSeederTest` asserts exact counts of existing entities; KB seeding must be purely additive. New `seedKnowledgeBase($team)` call appended in `run()`.
- `resources/views/layouts/app/sidebar.blade.php` — add `@can('manage knowledge base')`-gated item after Queue.
- Nested binding chain relies on relationship names matching route param plurals: `KnowledgeBook::chapters()`, `Chapter::pages()` — required by `scopeBindings()` convention (`{book:slug}/{chapter:slug}/{page:slug}` guesses `chapters`/`pages`).

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

### Phase 7

- **install:broadcasting interactivity**: the command may prompt (Reverb install, npm vs bun). Pass `--reverb --no-interaction`; if it tries `npm install`, it may add laravel-echo/pusher-js to package.json but run the wrong package manager — re-run `bun install` / `bun add laravel-echo pusher-js` and verify package.json. The scaffolded `resources/js/echo.js` may overwrite or expect an import in app.js (currently empty).
- **toOthers() in test context**: `broadcast(...)->toOthers()` reads the `X-Socket-ID` header; absent in Pest/queued runs → broadcasts to all (harmless). BroadcastTest should assert the event implements ShouldBroadcast + channel/payload shape via `Event::fake()` + `assertDispatched`, NOT assert toOthers filtering (that's a connection-time concern).
- **Channel auth test path**: hitting `POST /broadcasting/auth` requires the `channels:` route registered + a presence channel name + authenticated session. Simpler/robust: resolve the closure from `routes/channels.php` directly, or use Laravel's channel testing. The auth callback receives the route-model-bound `Request $helpdeskRequest` — binding a non-existent id 404s (graceful). Foreign-team member → closure returns false → 403; guest → guard denies before closure runs.
- **Payload-shape regression guard**: spec FAILURE MODE — a future dev fattening `broadcastWith` to include the note body would leak private-note text over the websocket. The BroadcastTest payload-shape assertion (`note_id` only) is the pin; keep it strict.
- **app.js empty**: `import './echo'` is the entire file's first content; passkeys.js is a separate vite input and must not be touched.
- **User has no avatar column**: viewer banner avatars use `<flux:avatar :name="$viewer['name']">` (initials), consistent with the existing timeline rendering — do not invent an avatar URL field in the channel_data payload.

### Phase 3

- **Spec payload-shape divergence (resolved)**: spec pseudocode assumed body/headers inline in the webhook payload; reality is a two-step fetch. `InboundEmail` DTO becomes the single parse point over (webhook payload + retrieved email JSON); fixtures bundle both parts per scenario.
- New listener fires in all existing AddNote tests — if any existing test asserts exact mail counts via the array transport it could break (none found, but watch ShowRequestTest).
- `mail:replay` must not hit the network: command `Http::fake()`s the Resend content/attachment endpoints from the fixture before running the job.
- Note XOR authorship invariant: "attachment could not be imported" private note needs an author — no system user exists; customer-authored private note chosen (logged in implementation notes).
- Package compatibility with Laravel 13 unverified until `composer require` runs (webhook-client, medialibrary).

### Phase 5

- Route param `{page}` (Page model) coexists with Livewire pagination's `page` query param — distinct (path segment vs query string) but worth a comment; avoid `WithPagination` on the portal page component.
- Implicit binding ignores published state — portal mount() must 404 drafts explicitly (`abort_unless($book->is_published, 404)` etc.); binding only proves hierarchy, not visibility.
- `App\Models\Page` is a generic name — no collision today (`Flux\Page`? no), but keep imports explicit.
- spatie/laravel-sluggable is a new dependency — spec-approved via composer.json entry; verify version supports `extraScope` (v3.7+).
- Permission middleware via `can:` uses the Gate; tests must seed PermissionSeeder before `assignRole('Administrator')`/`givePermissionTo` and may need `PermissionRegistrar` cache reset (seeder handles it).
- DemoSeeder KB content includes the searchable "password" page (spec demo: search "password" finds the published page, excludes a draft twin) — keep titles deterministic.

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
