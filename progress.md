# Session Progress Log

## Current State

**Last Updated:** 2026-06-10
**Active Feature:** feat-003 complete — Phase 3 (Shared Inbox & Email Pipeline) done

## Status

### What's Done

- [x] Ideation complete: contract approved at Full scope (docs/ideation/helpstripe/contract.md)
- [x] Eight implementation specs written and approved (docs/ideation/helpstripe/spec-phase-1.md … spec-phase-8.md)
- [x] feature_list.json synced to the 8 phases with dependencies + done_when criteria
- [x] **feat-001 Foundation & Domain Models (Phase 1)** — spatie permission/activitylog/tags installed; Request/Customer/Category/Mailbox/Note models + migrations + factories; RequestStatus/RequestSource enums; PermissionSeeder; DemoSeeder; 28 Foundation tests; docs/tour/01-foundation.md
- [x] **feat-002 Ticket Management (Phase 2)** — request queue (⚡index) with #[Url] criteria filters + saved Filters + save-filter modal; request detail (⚡show) with timeline/reply box/canned Response picker/properties panel/history tab; Filter + Response models; CreateRequest/AddNote/AssignRequest/ChangeStatus actions; 4 domain events; RequestAssignedNotification; RequestQueue query object; RequestPolicy; 61 Requests tests; docs/tour/02-ticket-management.md
- [x] **feat-005 Knowledge Base (Phase 5)** — KnowledgeBook/Chapter/Page models (HasSlug with per-parent extraScope, #[Scope] published, position max+1 in boot) + migrations (composite unique slugs, cascade FKs) + factories; spatie/laravel-sluggable v4 installed; admin manager SFCs pages/kb/⚡{index,book,edit-page} behind `can:manage knowledge base` (cross-team ids 404 in mount/actions); public portal: layouts/portal.blade.php + pages/portal/kb/⚡{index,book,page,search} with nested-slug routes + scopeBindings(); LIKE search with explicit ESCAPE clause; Str::markdown html_input=escape everywhere (editor preview + portal); sidebar @can nav item; DemoSeeder +2 books/3 chapters/10 pages; 49 KnowledgeBase tests; docs/tour/05-knowledge-base.md
- [x] **feat-003 Shared Inbox & Email Pipeline (Phase 3)** — three intake channels converging on Phase 2's CreateRequest/AddNote. Outbound: PublicReplyMail + NewRequestConfirmationMail (threading headers Message-ID/In-Reply-To/References + [#id] subject token), SendPublicReplyEmail queued listener on NoteAdded. Inbound: spatie/laravel-webhook-client store-then-process, ResendSignatureValidator (svix HMAC over raw body), InboundEmail DTO (single parse point), ProcessInboundEmail job (match→header/subject-token/new, reopen Resolved/Closed, idempotent on message_id, medialibrary attachments with size-cap skip→private note). mail:replay command (offline fixture replay). API: POST /api/v1/requests behind AuthenticateApiToken (static bearer + hash_equals), StoreRequestRequest (team-scoped category rule), RequestResource (201 + access_key), RequestController. 34 tests (Inbox 27 + Api 7); docs/tour/03-shared-inbox.md

### What's In Progress

- Nothing in flight.

### What's Next

1. feat-004 Self-Service Portal (depends on feat-003 ✅): `/ideation:execute-spec docs/ideation/helpstripe/spec-phase-4.md` — NOTE: Phase 5 already created `layouts/portal.blade.php` and the `portal` route group; Phase 4 must EXTEND both, not recreate (see implementation-notes-phase-5.html). The NewRequestConfirmationMail (access key) and Customer email+key auth model are already in place from Phase 3.
2. feat-006 Automation Rules (depends on feat-003 ✅): the Mail Rules seam is shipped — `ProcessInboundEmail::applyMailRules()` is a no-op pass-through Phase 6 fills.
3. Also unblocked: feat-007 Collision Detection (deps: feat-002), feat-008 Reporting (deps: feat-002)

## Blockers / Risks

- [ ] Resend live demo needs one-time external setup (domain + MX + webhook secret) — code path works offline via mail:replay; see spec-phase-3 Open Items
- [ ] API-shape verifications deferred to implementation (flagged in spec Open Items): Resend inbound payload/attachments, Flux chart props, Livewire 4 echo-presence attribute syntax

## Decisions Made

- **HelpSpot vocabulary in code**: App\Models\Request and App\Models\Response deliberately collide with framework classes — taught as a namespaces lesson
- **One seeded team = the installation**; spatie/laravel-permission is the helpdesk authorization layer
- **Real email via Resend both directions**; mail:replay command is the offline/test fallback
- **Reverb presence channels** for collision detection (user runs Laravel Herd locally)
- **Teaching comments are explicitly wanted** in this repo (overrides the global no-comments preference)
- **(Phase 1, user-directed)** UserFactory no longer auto-creates a personal team — opt-in via `withPersonalTeam()`
- **(Phase 1)** activitylog **v5** stores diffs in `activity.attribute_changes`; permission **v8** trait collision resolved via `insteadof` on User
- **(Phase 1)** `DatabaseSeeder` must not use `WithoutModelEvents`
- **(Phase 2)** activitylog v5 renamed the subject relation: use `activitiesAsSubject()`, not `activities()`
- **(Phase 2)** `resolved_at` semantics: stamped once on entering Resolved/Closed, cleared on reopening
- **(Phase 2)** Saved Filter criteria store symbolic `'me'` (resolved per-viewer by RequestQueue); unknown criteria keys ignored
- **(Phase 2)** Flux v2 modals: use `Flux::modal('name')->show()/close()` — `dispatch('close-modal', name:)` is a no-op
- **(Phase 2)** Added the framework `notifications` table migration (spec gap)
- **(Phase 5)** Portal route group must be registered BEFORE the `{current_team}` group — the team prefix would otherwise capture `/portal/...` as a team slug (and `kb/search` before `kb/{book:slug}`)
- **(Phase 5)** `layouts/portal.blade.php` created here (Phase 5 landed before Phase 4); `Route::has('portal.home')`/`Route::has('portal.kb.index')` guards keep phases order-independent — Phase 4 extends, never recreates
- **(Phase 5)** Admin KB routes bind by id (`kb/{book}`, flat `kb/pages/{page}`); nested-slug + scopeBindings() lesson lives on the portal only; cross-team admin access 404s via mount/action checks (permission-vs-policy contrast: no KnowledgeBookPolicy on purpose)
- **(Phase 5)** LIKE search needs an explicit `ESCAPE '\'` clause (`whereRaw`) — SQLite has no default LIKE escape character, so backslash-escaping `%`/`_` alone is MySQL-only behavior
- **(Phase 5)** Eager-load constraint closures receive the Relation (e.g. `HasMany`), not `Builder` — type hints must match or the closure TypeErrors
- **(Phase 5)** larastan + `@property int` docblocks: `$model->position === null` is flagged always-false; use `array_key_exists('position', $model->getAttributes())` in `creating` hooks
- **(Phase 5)** CommonMark treats a leading `<script>` line as an HTML block — inline markdown on the same line stays literal; test fixtures must separate paragraphs
- **(Phase 3)** Inbound matching order: threading headers (notes.message_id) → subject `[#id]` token (scoped to receiving mailbox's team) → new request on the `to`-address mailbox (fallback: first mailbox). Idempotent on message_id (Resend redelivery). Customer reply to Resolved/Closed reopens → Active.
- **(Phase 3)** Resend `email.received` webhook carries metadata only; body + headers + attachments are fetched from the Resend API in the job. Fixtures bundle all three payloads (`webhook`/`email`/`attachments`); InboundEmail DTO is the single parse point so a Resend schema change touches one file.
- **(Phase 3)** svix signature = HMAC-SHA256 over the literal `"{id}.{timestamp}.{raw body}"` keyed with the base64-decoded `whsec_` secret — must use `$request->getContent()` (raw), never re-encoded JSON; ±300s timestamp window; InvalidWebhookSignature → 401 (not 5xx, which would trigger retries)
- **(Phase 3)** Outbound Message-ID is deterministic (`note-{id}@{inbound_domain}`) so a retried queued listener regenerates the same id and persists it without round-tripping the send. Laravel `Headers` takes ids WITHOUT angle brackets (Symfony adds `<>` on the wire).
- **(Phase 3)** API: "one seeded team = the installation" → `installationTeam()` = `Team::orderBy('id')->first()`; static bearer token compared with `hash_equals` (timing-safe). Customer resolved case-insensitively (mirrors inbound). Sanctum named as production path in tour doc.
- **(Phase 3)** mail:replay fakes the Resend reads AND each attachment's CDN download_url (read from the fixture listing) with per-URL stubs — a trailing `*` wildcard leaked across fixtures in a replay-all run and intercepted the next content fetch
- **(Phase 3)** DemoSeeder mailbox addresses now derive from `config('helpstripe.inbound_domain')` (default helpstripe.test) so seeded support@/billing@ match a wired Resend domain
- **(Phase 3)** Mail Rules seam: `ProcessInboundEmail::applyMailRules($email)` ships as a no-op pass-through for Phase 6 to fill — the inbound pipeline never reopens

## Files Modified This Session (Phase 3)

- New (app): app/Mail/{PublicReplyMail,NewRequestConfirmationMail}.php, resources/views/mail/{public-reply,new-request-confirmation}.blade.php, app/Listeners/SendPublicReplyEmail.php, app/Jobs/ProcessInboundEmail.php, app/Support/Resend/{ResendSignatureValidator,InboundEmail}.php, app/Console/Commands/ReplayInboundMail.php, app/Http/Controllers/Api/V1/RequestController.php, app/Http/Resources/RequestResource.php, app/Http/Requests/Api/StoreRequestRequest.php, app/Http/Middleware/AuthenticateApiToken.php, config/{helpstripe,webhook-client}.php, routes/api.php, 3 migrations (create_media_table, create_webhook_calls_table, add_attachments_to_webhook_calls_table)
- New (tests + docs): tests/Fixtures/resend/{inbound-new,inbound-reply,inbound-attachment}.json, tests/Feature/Inbox/{InboundWebhookTest,InboundMatchingTest,InboundAttachmentTest,OutboundReplyTest,ReplayCommandTest}.php, tests/Feature/Api/CreateRequestApiTest.php, docs/tour/03-shared-inbox.md
- Modified: composer.json/lock (+resend/resend-php, spatie/laravel-webhook-client, spatie/laravel-medialibrary), config/mail.php (resend mailer), .env.example (RESEND_*/HELPSTRIPE_* keys), bootstrap/app.php (api routing + CSRF exempt webhooks/* + InvalidWebhookSignature→401 render), routes/web.php (Route::webhooks), app/Models/Request.php (findForInbound), app/Models/Note.php (HasMedia/InteractsWithMedia + attachments collection), app/Actions/Requests/{AddNote,CreateRequest}.php (optional message_id + attachments), database/seeders/DemoSeeder.php (mailbox addresses derive from inbound_domain), tests/Pest.php (resendFixture helper)
- Ideation artifacts: docs/ideation/helpstripe/context-map.md (Phase 3 extension), implementation-notes-phase-3.html (2 entries: API team resolution, mail:replay attachment faking)

## Evidence of Completion (Phase 3)

- `./init.sh` → composer install OK; pint passed; phpstan passed (0 errors); pest **228/228 passed (923 assertions)**
- `php artisan test --compact --filter=Inbox` → **27 passed** (Webhook 6, Matching 8, Attachment 3, Outbound 6, Replay 4)
- `php artisan test --compact --filter=CreateRequestApiTest` → **7 passed**
- `php artisan mail:replay` → replays inbound-attachment/inbound-new/inbound-reply offline (Http::fake'd); reply threads onto the request the first replay created
- `php artisan migrate:fresh --seed` → mailbox addresses support@/billing@helpstripe.test (derived from inbound_domain); prior Phase 1/2/5 dataset intact
- Review cycle: 2 of 3, verdict PASS. Cycle 1 found 1 high/testing (attachment size-cap skip→private-note had no coverage) → added InboundAttachmentTest (3 tests); 1 medium/logic (controller installationTeam()->id unguarded null deref) → abort_unless 503 guard. Cycle 2 clean. 1 low/testing noted, non-blocking: HTML-only empty-body inbound path not separately tested (strip_tags fallback is trivial; body-present path covered by all fixtures).

## Notes for Next Session

Two phases now unblocked by feat-003: **feat-004 Portal** and **feat-006 Automation**.
- **feat-004 Portal**: `NewRequestConfirmationMail` (request # + access_key) and the Customer email+key auth model already exist (Phase 3). `layouts/portal.blade.php` + the `portal` route group already exist (Phase 5) — EXTEND, never recreate; KB teaser guard is `Route::has('portal.kb.index')`. Customers have no user accounts.
- **feat-006 Automation**: the Mail Rules hook point is live — `ProcessInboundEmail::applyMailRules($email)` is a no-op pass-through; fill it without reopening the inbound pipeline. Triggers attach to the existing domain events (RequestCreated/NoteAdded/etc.) exactly like SendPublicReplyEmail does.
- **Reuse `CreateRequest` / `AddNote`** for any new intake — they own the domain events + first_responded_at; never write a parallel path.
- `AddNote::handle(Request, User|Customer $author, string $body, bool $isPrivate, RequestSource $source, ?string $messageId, array $attachments)` — customer replies pass the Customer author + RequestSource::Email + the inbound Message-ID.
- Real email needs one-time external setup (Resend domain + MX + webhook secret) — see docs/tour/03-shared-inbox.md §9B. Offline path is `php artisan mail:replay`.
- Run tests with `PAO_DISABLE=1 php vendor/bin/pest …` when you need real (non-JSON) failure output in agent sessions.
