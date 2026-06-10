# 01 — Foundation & Domain Models

This phase builds the data layer everything else stands on: the helpdesk
schema, the Eloquent models in HelpSpot vocabulary, the permission groups,
and a seeded demo installation. No helpdesk UI yet (the sidebar's **Queue**
item is a placeholder until Phase 2) — the deliverable is a schema you can
explore from tinker.

Files to read alongside this doc:

- `database/migrations/2026_06_10_*` — the five helpdesk tables
- `app/Models/{Request,Customer,Category,Mailbox,Note}.php`
- `app/Enums/{RequestStatus,RequestSource}.php`
- `database/factories/{Request,Customer,Category,Mailbox,Note}Factory.php`
- `database/seeders/{PermissionSeeder,DemoSeeder}.php`
- `tests/Feature/Foundation/`

## 1. Migrations: the schema as code

A migration is a versioned schema change — a PHP class with an `up()` that
applies it and a `down()` that reverts it. Laravel runs them in filename
(timestamp) order and records what ran in a `migrations` table.

Run the whole set against a fresh database:

```bash
php artisan migrate:fresh
```

Ordering is the first lesson. Open
`database/migrations/2026_06_10_140534_create_requests_table.php`: the
`requests` table has foreign keys to `customers`, `categories`,
`mailboxes`, and `users` — so every one of those tables must be created
*before* it. That's why the five helpdesk migrations carry sequential
timestamps (…531 customers, …532 categories, …533 mailboxes, …534
requests, …535 notes): filename order **is** dependency order. (Fun fact:
generating them in one batch gave all five the same timestamp, which would
have run them alphabetically — notes before requests — and failed on the
foreign key. The rename was deliberate.)

Notice in the requests migration:

- `$table->foreignId('customer_id')->constrained()` — convention-based FK:
  the column name implies the `customers` table.
- `$table->foreignId('assigned_to')->nullable()->constrained('users')` —
  the column name *doesn't* follow convention, so the table is explicit.
- `$table->index(['team_id', 'status'])` — composite indexes shaped like
  the queue's hottest queries.

## 2. Models and relations: the object graph

An Eloquent model is a class mapped to a table; relations turn foreign
keys into navigable properties. Open `app/Models/Request.php` and find the
relation methods: `customer()`, `category()`, `mailbox()`, `assignee()`,
`notes()`.

Trace one in tinker:

```bash
php artisan migrate:fresh --seed
php artisan tinker
```

```php
>>> $r = App\Models\Request::with('customer', 'notes')->first();
>>> $r->customer->name;          // belongsTo: notes the FK on requests
>>> $r->notes->count();          // hasMany: FK lives on notes
>>> $r->assignee?->name;         // belongsTo with explicit key 'assigned_to'
```

`with('customer')` is *eager loading* — it fetches all customers for the
result set in one extra query instead of one query per request (the "N+1"
problem you'll hear about constantly).

### The name-collision lesson

The model is deliberately named `App\Models\Request`, colliding with the
framework's `Illuminate\Http\Request` — exactly the collision HelpSpot's
own vocabulary creates. PHP resolves bare class names against the current
namespace and its `use` statements, so:

- Inside `App\Models`, `Request` means our model. Nothing special needed.
- In a file that needs **both**, alias the framework one:

```php
use App\Models\Request;
use Illuminate\Http\Request as HttpRequest;

public function store(HttpRequest $http): Request { /* … */ }
```

The same lesson appeared in trait form: spatie's `HasRoles` and the
starter kit's `HasTeams` both define a `teams()` method. Open
`app/Models/User.php` to see PHP's trait conflict resolution
(`insteadof` / `as`) choosing the starter's relation and shelving
spatie's under an alias.

## 3. Enums as status fields

`app/Enums/RequestStatus.php` is a *string-backed enum*: each case carries
the string stored in the database. The model's `casts()` method
(`'status' => RequestStatus::class`) makes hydration automatic — reads
give you a type-safe enum instance, writes accept the enum.

```php
>>> $r = App\Models\Request::first();
>>> $r->status;                  // App\Enums\RequestStatus instance
>>> $r->status->label();         // "Active"
>>> $r->status->color();         // "blue" — Flux badge color, Phase 2
>>> $r->getRawOriginal('status') // "active" — what SQLite actually stores
```

Keeping `label()` and `color()` on the enum centralizes presentation
decisions every Blade view will share.

## 4. Model events: the access key

Every request carries a 12-character `access_key` — the credential a
customer will use (with their email) to view their request on the Phase 4
portal. Nobody passes it in; the model generates it in a `creating` event
(see `Request::boot()`), Eloquent's hook just before the INSERT:

```php
>>> App\Models\Request::factory()->create()->access_key;
=> "fY3kP9mQx2Lw" // yours will differ
```

Compare `Team::boot()` in the starter kit — slugs are derived the same
way. When a value must always exist and the caller shouldn't supply it, a
model event is the idiomatic home.

One sharp edge worth knowing: seeders that use Laravel's
`WithoutModelEvents` trait silence these hooks for everything they call —
which is why `DatabaseSeeder` pointedly does **not** use it.

## 5. Factories and seeders

Factories build models with fake-but-plausible data for tests and demos.
`database/factories/RequestFactory.php` shows the two patterns worth
stealing:

- **States** — chainable named variations: `urgent()`, `resolved()`,
  `aged($from, $until)`, `withFirstResponse($minutes)`.
- **Dependent attributes** — closures that read already-resolved
  attributes, like the customer inheriting the request's `team_id`.

```php
>>> App\Models\Request::factory()->urgent()->create()->is_urgent; // true
```

`database/seeders/DemoSeeder.php` composes the factories into a full demo
installation (the README documents the dataset). It's deterministic in
shape — index-derived statuses, fixed counts — so
`tests/Feature/Foundation/DemoSeederTest.php` can assert exact numbers.
Those tests also freeze the clock (`$this->travelTo(...)`) because the
seeder spreads requests over "the last 60 days" relative to `now()`:
time-dependent data + a moving clock = flaky tests.

## 6. spatie/laravel-permission in five minutes

HelpSpot organizes staff into *permission groups*; we mirror that with the
de-facto standard package. Three ideas:

1. **Permissions** are named abilities (`manage automation`, `view
   reports`) stored as rows.
2. **Roles** bundle permissions (`Administrator` has all five, `Help Desk
   Staff` has none — in HelpSpot, working the queue is a membership right,
   not a permission).
3. **Assignment** glues them to users; Laravel's standard `can()` then
   consults the package automatically via a Gate hook.

```php
>>> $sam = App\Models\User::where('email', 'sam@helpstripe.test')->first();
>>> $sam->hasRole('Administrator');   // true
>>> $sam->can('manage automation');   // true
>>> $riley = App\Models\User::where('email', 'riley@helpstripe.test')->first();
>>> $riley->can('manage automation'); // false
```

Two details from `database/seeders/PermissionSeeder.php`: it calls
`forgetCachedPermissions()` first (the package caches the permission table
aggressively), and it uses `firstOrCreate` so seeding is idempotent.

Note there are *two* authorization layers in this app, on purpose: spatie
roles govern helpdesk abilities; the starter kit's `TeamRole` enum (Owner /
Admin / Member on the `team_members` pivot) keeps governing the
team-settings screens. They answer different questions.

## 7. The request history (activity log)

`Request` uses spatie/laravel-activitylog's `LogsActivity` trait, scoped
(via `getActivitylogOptions()`) to the four attributes that matter on a
ticket timeline: `status`, `assigned_to`, `category_id`, `is_urgent`.

```php
>>> $r = App\Models\Request::first();
>>> $r->update(['status' => App\Enums\RequestStatus::Resolved]);
>>> Spatie\Activitylog\Models\Activity::where('subject_id', $r->id)
...     ->where('event', 'updated')->latest('id')->first()
...     ->attribute_changes->toArray();
=> ["attributes" => ["status" => "resolved"], "old" => ["status" => "active"]]
```

Version note: activitylog **v5** stores these diffs in the
`attribute_changes` column — older docs (and most blog posts) show them
under `properties`, which v5 reserves for custom data.

## 8. Demo script

The end-to-end check that this phase works:

```bash
php artisan migrate:fresh --seed
php artisan test --compact --filter=Foundation
php artisan tinker
```

```php
// 1. The documented dataset
>>> [App\Models\Request::count(), App\Models\Note::count() >= 40, App\Models\Customer::count(), App\Models\Team::count()];
=> [40, true, 8, 1]

// 2. A coherent request graph
>>> App\Models\Request::with('customer', 'category', 'notes')->first()->toArray();

// 3. The queue shape Phase 2 will render
>>> App\Models\Request::whereNull('assigned_to')->count();   // 10
>>> App\Models\Request::where('is_urgent', true)->count();   // 4

// 4. Permissions
>>> App\Models\User::where('email', 'sam@helpstripe.test')->first()->can('manage staff'); // true
```

Then log in at the app (via `composer run dev` or Herd) as
`sam@helpstripe.test` / `password`: the dashboard renders and the sidebar
shows the **Queue** placeholder. Phase 2 turns it into the real request
queue.

## 9. Verify

```bash
php artisan test --compact --filter=Foundation   # schema, models, enums, factories, seeder
./init.sh                                         # lint + static analysis + full suite
```

The `Foundation` suite covers the migration/relation graph, the enums and
their casts, the `access_key` model event, the factories and their states,
and `DemoSeederTest`'s exact-count assertions over the seeded installation.
