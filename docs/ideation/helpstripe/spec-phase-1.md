# Implementation Spec: HelpStripe - Phase 1: Foundation & Domain Models

**Contract**: ./contract.md
**Estimated Effort**: M

## Technical Approach

This phase lays the domain foundation every other phase builds on: the core helpdesk models in HelpSpot vocabulary (`Request`, `Customer`, `Category`, `Mailbox`), the `RequestStatus` enum, spatie-backed permission groups, and a rich demo seeder. No user-facing helpdesk UI ships in this phase beyond a sidebar entry placeholder — the deliverable is a schema, factories, seed data, and the first tour doc.

Three Spatie packages install here because their lessons belong to the foundation: `spatie/laravel-permission` (HelpSpot permission groups), `spatie/laravel-activitylog` (request history), and `spatie/laravel-tags` (request tagging). The packages that belong to later lessons (webhook-client, medialibrary, sluggable) install in their own phases so each tour doc teaches its dependency in context.

One deliberate teaching decision: the Eloquent model is named `App\Models\Request` — colliding with `Illuminate\Http\Request` exactly the way HelpSpot's own domain vocabulary does. Files needing both alias the framework class (`use Illuminate\Http\Request as HttpRequest;`). The tour doc calls this out as a namespaces lesson rather than avoiding it.

This is a teaching repo: annotated code is explicitly requested. Write teaching comments (docblocks explaining the Laravel concept at point of use) — this overrides the usual no-comments convention.

## Feedback Strategy

**Inner-loop command**: `php artisan test --compact --filter=Foundation`

**Playground**: Pest test suite (models/factories are data-layer work) plus `php artisan migrate:fresh --seed` + `php artisan tinker` for inspecting seeded data.

**Why this approach**: All components are data-layer; a scoped test run plus tinker inspection is the tightest loop. No UI to iterate on.

## File Changes

### New Files

| File Path | Purpose |
| --- | --- |
| `app/Models/Request.php` | Core helpdesk request (HelpSpot vocabulary), activitylog + tags traits |
| `app/Models/Customer.php` | Customer identified by email; no user account |
| `app/Models/Category.php` | Request category with optional SLA first-response target |
| `app/Models/Mailbox.php` | Inbound email identity (address → default category) |
| `app/Models/Note.php` | Request timeline entry: public reply or private note, staff- or customer-authored |
| `app/Enums/RequestStatus.php` | Active / Pending / Resolved / Closed with labels + Flux badge colors |
| `app/Enums/RequestSource.php` | Email / Portal / Api / Agent |
| `database/migrations/*_create_customers_table.php` | Customers schema |
| `database/migrations/*_create_categories_table.php` | Categories schema |
| `database/migrations/*_create_mailboxes_table.php` | Mailboxes schema |
| `database/migrations/*_create_requests_table.php` | Requests schema |
| `database/migrations/*_create_notes_table.php` | Notes schema |
| `database/factories/RequestFactory.php` | Request factory with states (`active`, `resolved`, `urgent`, `aged`, `withFirstResponse`) |
| `database/factories/CustomerFactory.php` | Customer factory |
| `database/factories/CategoryFactory.php` | Category factory |
| `database/factories/MailboxFactory.php` | Mailbox factory |
| `database/factories/NoteFactory.php` | Note factory with `private`/`fromCustomer` states |
| `database/seeders/DemoSeeder.php` | Full demo helpdesk dataset (called from DatabaseSeeder) |
| `database/seeders/PermissionSeeder.php` | Roles + permissions (Administrator, Help Desk Staff) |
| `tests/Feature/Foundation/RequestModelTest.php` | Relations, enum casts, access key generation, activity log |
| `tests/Feature/Foundation/PermissionTest.php` | Role/permission assignment works |
| `tests/Feature/Foundation/DemoSeederTest.php` | Seeder produces the documented dataset |
| `docs/tour/README.md` | Tour index: pillar → phase → doc map |
| `docs/tour/01-foundation.md` | Tour doc: migrations, Eloquent relations, enums, factories, seeders, spatie permission |

### Modified Files

| File Path | Changes |
| --- | --- |
| `composer.json` | Add spatie/laravel-permission, spatie/laravel-activitylog, spatie/laravel-tags (via `composer require`) |
| `app/Models/User.php` | Add `HasRoles` trait, `assignedRequests()` relation |
| `database/seeders/DatabaseSeeder.php` | Call PermissionSeeder + DemoSeeder |
| `resources/views/layouts/app/sidebar.blade.php` | Add "Queue" nav item (routes to dashboard until Phase 2 ships the page) |

## Implementation Details

### Spatie package installation

**Overview**: `composer require spatie/laravel-permission spatie/laravel-activitylog spatie/laravel-tags`, then `vendor:publish` each package's migrations (`permission-migrations`, `activitylog-migrations`, `tags-migrations`) and the permission config.

**Key decisions**:
- Single-team installation (one seeded team = the HelpSpot installation), so spatie's teams feature stays **off** (`'teams' => false` in config/permission.php).
- The starter's `TeamRole`/`TeamPermission` enums keep governing team-settings screens untouched; spatie roles govern helpdesk authorization. The tour doc explains why both exist.

**Implementation steps**:
1. `composer require` the three packages (run `php artisan boost:mcp search-docs` / Boost `search-docs` for each package's current install steps before wiring).
2. Publish and run migrations.
3. Add `HasRoles` to `User`.

No feedback loop — config/install work, verified by the permission test.

### Domain models & migrations

**Pattern to follow**: `app/Models/Team.php` (casts, relations, docblocks), `database/factories/TeamFactory.php`

**Overview**: Five models forming the helpdesk core. All helpdesk tables carry `team_id` scoping to the single seeded team.

Schema (SQLite-friendly):

```php
// requests
$table->id();                                  // doubles as the public request number
$table->foreignId('team_id')->constrained();
$table->foreignId('customer_id')->constrained();
$table->foreignId('category_id')->nullable()->constrained();
$table->foreignId('mailbox_id')->nullable()->constrained();
$table->foreignId('assigned_to')->nullable()->constrained('users');
$table->string('subject');
$table->string('status')->default(RequestStatus::Active->value);  // enum cast
$table->string('source');                       // enum cast
$table->boolean('is_urgent')->default(false);
$table->string('access_key', 16);               // portal lookup credential
$table->timestamp('first_responded_at')->nullable();
$table->timestamp('resolved_at')->nullable();
$table->timestamps();
$table->index(['team_id', 'status']);
$table->index(['assigned_to', 'status']);

// notes
$table->id();
$table->foreignId('request_id')->constrained()->cascadeOnDelete();
$table->foreignId('user_id')->nullable()->constrained();      // staff author
$table->foreignId('customer_id')->nullable()->constrained();  // customer author
$table->text('body');
$table->boolean('is_private')->default(false);
$table->string('source');                       // RequestSource enum cast
$table->string('message_id')->nullable()->index(); // outbound/inbound email threading (Phase 3)
$table->timestamps();

// customers: team_id, name, email (unique per team), timestamps
// categories: team_id, name, sla_first_response_minutes (nullable unsignedInteger), timestamps
// mailboxes: team_id, name, address (unique), category_id nullable (default category), timestamps
```

**Key decisions**:
- `Request` uses `LogsActivity` (activitylog) logging `status`, `assigned_to`, `category_id`, `is_urgent` changes — this IS the "full history" tour claim.
- `HasTags` on `Request`.
- `access_key` generated in a `creating` model event (`Str::random(12)`) — teaches model events/observers inline.
- Notes use two nullable FKs (user_id XOR customer_id) instead of polymorphic authors — simpler to teach, and the constraint is named in the tour doc.
- `casts()`: `status` / `source` to enums, timestamps to datetime.

**Implementation steps**:
1. `php artisan make:model -mf` for each model (`--no-interaction`).
2. Write migrations in dependency order (customers/categories/mailboxes before requests, notes last).
3. Add relations: Request belongsTo Customer/Category/Mailbox/assignee, hasMany Notes; Customer hasMany Requests; Category & Mailbox hasMany Requests.
4. Add `RequestStatus` (with `label()` and `color()` helpers for Flux badges) and `RequestSource` enums.
5. Add teaching docblocks throughout.

**Feedback loop**:
- **Playground**: `tests/Feature/Foundation/RequestModelTest.php` with one smoke test (`Request::factory()->create()` persists) before fleshing out models.
- **Experiment**: factory states — create urgent/resolved/aged requests; assert enum casts round-trip; assert access_key auto-generates and is 12 chars; update status and assert an activity log row exists.
- **Check command**: `php artisan test --compact --filter=RequestModelTest`

### Permission groups

**Pattern to follow**: spatie docs (verify via Boost `search-docs` with `['permission roles', 'permission seeding']`)

**Overview**: Two roles mirroring HelpSpot permission groups: `Administrator` and `Help Desk Staff`. Permissions: `manage categories`, `manage knowledge base`, `manage automation`, `view reports`, `manage staff`. Admins get all; staff get none of the manage-* permissions but can work requests (request actions check membership, not permissions, matching HelpSpot where all staff work the queue).

**Implementation steps**:
1. `PermissionSeeder` creates permissions + roles idempotently (`firstOrCreate`).
2. Assign roles in `DemoSeeder`.
3. Test: staff user lacks `manage automation`; admin has it.

No feedback loop beyond the test — write-once seeder.

### Demo seeder

**Overview**: `DemoSeeder` builds the entire demo installation deterministically (fixed names, faker for flavor):
- 1 team: "HelpStripe Support" (uses existing `CreateTeam`-style flow or Team factory; all users members).
- 4 staff: Sam Administrator (admin role), three Help Desk Staff.
- 3 categories: Billing (SLA 60m), Technical Support (SLA 240m), Sales (no SLA).
- 2 mailboxes: `support@…` → Technical Support, `billing@…` → Billing.
- 8 customers.
- ~40 requests spread across the last 60 days (so Phase 8 charts have shape): mixed statuses, ~25% unassigned, ~10% urgent, some with `first_responded_at` inside/outside SLA targets, each with 1–6 notes (mix of customer/public/private).

**Key decisions**:
- Seeder is idempotent enough for `migrate:fresh --seed` (the only supported path — document that, don't engineer re-runnability).
- Dates spread via factory `aged` state taking a `CarbonImmutable` range; **no reliance on `now()` randomness in tests** — tests freeze time with `Carbon::setTestNow()` / Pest `travel()`.

**Feedback loop**:
- **Playground**: `php artisan migrate:fresh --seed` then `php artisan tinker --execute 'dump(App\Models\Request::count(), App\Models\Note::count());'`
- **Experiment**: seed twice from fresh; counts match documented ranges; every request has ≥1 note; unassigned and urgent subsets non-empty.
- **Check command**: `php artisan test --compact --filter=DemoSeederTest`

### Tour docs (README + 01-foundation)

**Overview**: `docs/tour/README.md` maps the six HelpSpot pillars to phases/docs. `01-foundation.md` walks: what a migration is → run one; what a model/relation is → trace `Request::with('customer')` in tinker; enums as status fields; factories/seeders; spatie permission in 5 minutes; the `Request` name-collision lesson. Ends with a "demo script": fresh seed + tinker exploration commands.

No feedback loop — documentation.

## Data Model

See migrations above. Spatie packages add `permissions`/`roles`/`model_has_*`, `activity_log`, and `tags`/`taggables` tables via their published migrations.

## Testing Requirements

| Test File | Coverage |
| --- | --- |
| `tests/Feature/Foundation/RequestModelTest.php` | Relations load; enum casts; access_key on create; activity log rows on status/assignment change; tags attach |
| `tests/Feature/Foundation/PermissionTest.php` | Roles exist post-seed; admin has manage permissions, staff doesn't |
| `tests/Feature/Foundation/DemoSeederTest.php` | Seeder dataset shape (counts, unassigned subset, urgent subset, notes present) |

**Key test cases**: enum cast round-trip; note with customer author and null user; request with null category/mailbox; activity log captures old/new values.

### Manual Testing

- [ ] `php artisan migrate:fresh --seed` completes without error
- [ ] Tinker: `Request::with('customer','notes')->first()` shows a coherent request
- [ ] Sidebar shows Queue item; app still boots and dashboard renders

## Error Handling

| Error Scenario | Handling Strategy |
| --- | --- |
| Seeder run on non-fresh DB | Unsupported; document `migrate:fresh --seed` as the only path |
| Note with both user_id and customer_id | Prevented in factories/actions; documented invariant (no DB check constraint — SQLite teaching simplicity) |

## Failure Modes

| Component | Failure Mode | Trigger | Impact | Mitigation |
| --- | --- | --- | --- | --- |
| Request model | Name collision confusion | `use App\Models\Request` + framework Request in same file | Compile error / wrong class resolved | Alias convention documented in tour doc; Pint/PHPStan catch wrong usage |
| Migrations | FK order failure | requests migrated before customers | migrate fails | Timestamp-ordered migrations; covered by every test run (RefreshDatabase) |
| DemoSeeder | Time-dependent flakiness | tests asserting on aged data without frozen clock | intermittent failures | Freeze time in tests; factory takes explicit date ranges |
| Permission setup | Cached permissions stale | spatie permission cache after seeding in tests | role checks fail | Call `app(PermissionRegistrar::class)->forgetCachedPermissions()` in seeder (per spatie docs) |

## Validation Commands

```bash
composer lint                 # Pint
php artisan test --compact --filter=Foundation
composer test                 # Pint check + PHPStan + full Pest suite
./init.sh                     # full harness verification
```

## Rollout Considerations

None — local teaching repo. After completion: update `feature_list.json` (this phase's feature → `done` with evidence) and `progress.md` per the repo harness rules.

## Open Items

- [ ] Verify current spatie package install/publish commands via Boost `search-docs` before wiring (package majors move).
