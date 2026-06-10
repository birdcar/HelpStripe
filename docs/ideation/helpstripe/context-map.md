# Context Map: helpstripe

**Phase**: 1
**Scout Confidence**: 87/100
**Verdict**: GO

> Note: Scout ran inline (no Agent subagent tool available in this environment); same workflow, same read-only exploration.

## Dimensions

| Dimension            | Score | Notes                                                                                                          |
| -------------------- | ----- | -------------------------------------------------------------------------------------------------------------- |
| Scope clarity        | 19/20 | Spec enumerates every new/modified file with full schemas and factory states. Only timestamps of migrations open. |
| Pattern familiarity  | 18/20 | Read `app/Models/Team.php`, `database/factories/TeamFactory.php`, `app/Enums/TeamRole.php`, `app/Concerns/HasTeams.php`, `tests/Feature/Teams/TeamTest.php`, `tests/Pest.php`. Spatie packages not yet installed — install steps verified against vendor docs post-require. |
| Dependency awareness | 16/20 | All new files are self-contained. Modified files are additive: `User.php` (+trait, +relation), `DatabaseSeeder.php` (+seeder calls), `sidebar.blade.php` (+nav item). No existing consumers break. |
| Edge case coverage   | 16/20 | Enum cast round-trips, access_key creating-event, note user_id XOR customer_id, frozen-clock seeder tests, spatie permission cache reset, `App\Models\Request` vs `Illuminate\Http\Request` collision. |
| Test strategy        | 18/20 | Pest 4 + RefreshDatabase bound to `tests/Feature` via `tests/Pest.php`. `composer lint` = pint; `composer test` = config:clear + pint --test + phpstan + pest. Filter: `php artisan test --compact --filter=Foundation`. |

## Key Patterns

- `app/Models/Team.php` — model conventions: `#[Fillable([...])]` attribute (not `$fillable` property), `@property` docblocks for all columns and `@property-read` for relations, `casts()` method (not `$casts` property), typed relation return docblocks (`@return HasMany<Membership, $this>`), `static::creating(...)` in `boot()` for derived attributes (slug ≈ access_key pattern).
- `database/factories/TeamFactory.php` — factory conventions: `@extends Factory<Model>` docblock, `fake()` helper (not `$this->faker`), named state methods returning `static` via `$this->state(fn (array $attributes) => [...])`.
- `app/Enums/TeamRole.php` — enum conventions: string-backed, TitleCase cases, `label()` helper, `match($this)` for derived values, docblocks on every method.
- `tests/Feature/Teams/TeamTest.php` — Pest style: top-level `test('...', function () {...})`, `User::factory()->create()`, `$this->actingAs(...)`, `assertDatabaseHas`.
- `tests/Pest.php` — `RefreshDatabase` already applied to everything in `tests/Feature`; new Foundation tests need no per-file `uses()`.

## Dependencies

- `app/Models/User.php` — consumed by → auth flows, `HasTeams` concern, Fortify actions, Livewire components, `UserFactory`. Changes are purely additive (add `HasRoles` trait + `assignedRequests()` relation) — no existing signature changes.
- `database/seeders/DatabaseSeeder.php` — consumed by → `migrate:fresh --seed` only. Note: uses `WithoutModelEvents`; DemoSeeder must NOT use that trait (access_key relies on the `creating` model event).
- `resources/views/layouts/app/sidebar.blade.php` — consumed by → `layouts/app.blade.php` wrapper used by all authed pages. Additive `flux:sidebar.item` only.
- `composer.json` — three new spatie requires; `composer lint`/`composer test` scripts unchanged.
- New models/migrations/factories/seeders/tests — no external consumers; self-contained until Phase 2.

## Conventions

- **Naming**: models singular TitleCase; factories `{Model}Factory`; seeders `{Thing}Seeder`; tests `{Thing}Test.php` under `tests/Feature/{Domain}/`.
- **Imports**: fully-qualified `use` statements, alphabetized (Pint-enforced); framework `Request` must be aliased `use Illuminate\Http\Request as HttpRequest;` in files that also use `App\Models\Request`.
- **Attributes over properties**: `#[Fillable]`, `#[Hidden]` PHP attributes instead of protected properties (Laravel 13 starter style).
- **Error handling**: model invariants via events/factories, not DB check constraints (SQLite teaching simplicity).
- **Types**: explicit return types everywhere; generics in docblocks (`@return BelongsTo<Customer, $this>`); larastan level enforced via `composer test`.
- **Testing**: Pest 4, `test()` functions, feature tests by default, `RefreshDatabase` global for Feature dir, faker via `fake()`.
- **Teaching comments**: this repo explicitly wants teaching docblocks at point of use (overrides global no-comments preference).

## Risks

- `App\Models\Request` collides with `Illuminate\Http\Request` — deliberate. PHPStan will catch wrong resolution; alias convention documented in tour doc.
- `DatabaseSeeder` uses `WithoutModelEvents` — if DemoSeeder is invoked via `$this->call()` from it, model events still fire inside DemoSeeder unless DemoSeeder itself uses the trait. Verify access_key generation works under `migrate:fresh --seed` (test covers it).
- Spatie permission caches roles/permissions — seeder must call `app(PermissionRegistrar::class)->forgetCachedPermissions()` before creating permissions, or test role checks may fail.
- `users` migration has no `team_id`; requests `assigned_to` FK references `users` — fine. `customers.email` unique **per team** needs a composite unique index `['team_id', 'email']`, not a plain unique.
- Time-dependent demo data: tests must freeze time (`Carbon::setTestNow()` / Pest `travel()`); factory `aged` state takes explicit date ranges.
- Spatie package majors vs Laravel 13: composer resolution will surface incompatibility immediately at require-time; Boost search-docs has no spatie index until packages are installed.
