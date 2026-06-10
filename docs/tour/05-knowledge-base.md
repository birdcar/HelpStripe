# 05 — Knowledge Base

This phase ships a HelpSpot-shaped knowledge base: **Knowledge Books →
Chapters → Pages**, managed by staff who hold a permission, browsed by
anonymous customers on the portal, and searched with a deliberately
naive `LIKE` query. It is the smallest phase by machinery but the
densest by Eloquent lessons: nested relations, `hasManyThrough`, scoped
slugs, scoped route bindings, query scopes, and safe Markdown rendering.

Files to read alongside this doc:

- `app/Models/{KnowledgeBook,Chapter,Page}.php`
- `database/migrations/*_create_{knowledge_books,chapters,pages}_table.php`
- `routes/web.php` — the public `portal/kb` group and the gated `kb` group
- `resources/views/pages/kb/⚡{index,book,edit-page}.blade.php` (admin)
- `resources/views/pages/portal/kb/⚡{index,book,page,search}.blade.php` (portal)
- `resources/views/layouts/portal.blade.php`
- `tests/Feature/KnowledgeBase/`

## 1. The hierarchy: three models, two FKs

```text
knowledge_books   id, team_id, name, slug, description, is_published, position
chapters          id, knowledge_book_id (cascade), name, slug, position
pages             id, chapter_id (cascade), title, slug, body, is_published, position
```

Each level belongs to exactly one parent, and `cascadeOnDelete` on both
FKs means the database — not application code — cleans up the subtree
when a chapter or book is deleted. `AdminCrudTest` proves deleting a
chapter takes its pages with it.

### hasManyThrough

`pages` has no `book_id` column, yet `KnowledgeBook::pages()` exists:

```php
public function pages(): HasManyThrough
{
    return $this->hasManyThrough(Page::class, Chapter::class);
}
```

Eloquent joins `pages` to `chapters` and filters by
`chapters.knowledge_book_id` — a grandparent reaching grandchildren
through the middle table. The admin books index uses it for the page
counts (`withCount('pages')`), and the portal uses a constrained variant
to count only published pages.

## 2. Scoped slugs (spatie/laravel-sluggable)

Every level is sluggable, and every slug is unique *within its parent*,
not globally:

```php
public function getSlugOptions(): SlugOptions
{
    return SlugOptions::create()
        ->generateSlugsFrom('title')
        ->saveSlugsTo('slug')
        ->extraScope(fn (Builder $builder) => $builder->where('chapter_id', $this->chapter_id));
}
```

`extraScope` is the whole lesson: without it, the second book titled
"Introduction" anywhere in the database would get `introduction-1`. With
it, two books can each have an "Introduction" chapter and an
"Introduction" page — but two pages *in the same chapter* still
deduplicate (`introduction`, `introduction-1`). Composite unique indexes
(`unique(['chapter_id', 'slug'])`) back the application-level check at
the database level.

Renaming regenerates the slug (sluggable's default). The old URL 404s —
there is no redirect table. That's an accepted trade-off here, named in
Future Considerations below.

## 3. Scoped route bindings: *the* binding lesson of the repo

The portal's article URL carries all three slugs:

```php
Route::livewire('kb/{book:slug}/{chapter:slug}/{page:slug}', 'pages::portal.kb.page')
    ->name('kb.page')
    ->scopeBindings();
```

Because slugs are only unique per parent, resolving `{page:slug}`
against the whole `pages` table would be ambiguous — and worse,
exploitable: a slug that exists in two books must never serve the wrong
book's content. `scopeBindings()` tells Laravel to resolve each child
*through its parent's relationship*: `{chapter:slug}` via
`$book->chapters()`, `{page:slug}` via `$chapter->pages()` (the
convention: route parameter name, pluralized). A page requested under
the wrong book isn't a wrong answer — it's a 404 during binding, before
`mount()` ever runs. `PortalBrowsingTest` covers both directions.

Route registration order matters twice in `routes/web.php`, and both
are comments in the file: the `portal` group must precede the
`{current_team}` group (or `/portal/...` would be captured with
`current_team = "portal"`), and `kb/search` must precede
`kb/{book:slug}`.

## 4. Query scopes and the visibility matrix

`KnowledgeBook` and `Page` each define a `published()` scope using
Laravel's `#[Scope]` attribute — the first local scopes in this repo:

```php
#[Scope]
protected function published(Builder $query): void
{
    $query->where('is_published', true);
}
```

The subtle rule: **a page is portal-visible only when BOTH it and its
book are published.** Chapters have no flag; the book gates the whole
subtree. So every portal query pairs the two checks:

```php
Page::published()
    ->whereHas('chapter.book', fn (Builder $query) => $query->where('is_published', true))
```

and the article page's `mount()` does the same with an
`abort_unless(..., 404)` — route binding proves the hierarchy, not the
visibility. `PortalBrowsingTest` runs the full 2×2 matrix
(book × page, published × draft) as a Pest dataset; only
published/published returns 200.

## 5. Markdown rendering: `{!! !!}` made safe

Page bodies are raw Markdown, rendered server-side:

```php
public function renderedBody(): HtmlString
{
    return new HtmlString(
        Str::markdown($this->body, ['html_input' => 'escape'])
    );
}
```

`Str::markdown()` is CommonMark under the hood, and CommonMark passes
raw HTML through *by default* — `<script>` in, `<script>` out. The
`html_input => escape` option is what neutralizes stored XSS: authored
HTML comes out as visible text while real Markdown still becomes markup.
The portal page and the editor preview share this exact configuration,
so what the author previews is what the customer gets. Both surfaces
have a `<script>` fixture test.

The admin editor's live preview is a `#[Computed]` property over the
*unsaved* textarea state (`wire:model.live.debounce.300ms`) — rendering
on every debounced keystroke without persisting anything.

## 6. Permission middleware vs. policies

Phase 2 gated requests with a **policy** — membership-based: any staff
member of the team may work its queue. The KB manager is gated by a
**permission** — `manage knowledge base`, seeded in Phase 1, held by the
Administrator role and not by Help Desk Staff:

```php
Route::middleware('can:manage knowledge base')->group(function () { ... });
```

There is no `KnowledgeBookPolicy`. Laravel's stock `can:` middleware
asks the Gate, and spatie/laravel-permission registers every permission
name as a Gate ability — so middleware, `@can` in Blade (the sidebar
item), and `$user->can()` all consult the same source. The two-layer
contrast is the lesson: *membership* says which team's data you may
touch at all (`EnsureTeamMembership` + the cross-team 404s in mount),
*permission* says which features you may use.

`position` ordering is the same story told small: max+1 per parent on
create (`static::creating` in `boot()`), and reordering is two UPDATEs
swapping integers — no drag-and-drop library, on purpose.

## 7. Search: honestly naive

```php
$like = '%'.addcslashes($term, '\%_').'%';

Page::published()
    ->whereHas('chapter.book', fn (Builder $query) => $query->where('is_published', true))
    ->where(function (Builder $query) use ($like) {
        $query->whereRaw('title LIKE ? ESCAPE ?', [$like, '\\'])
            ->orWhereRaw('body LIKE ? ESCAPE ?', [$like, '\\']);
    })
```

Two details worth stealing:

- `%` and `_` are LIKE wildcards. Unescaped, a user searching `%` gets
  every page. `addcslashes` escapes them — and the `ESCAPE '\'` clause
  is explicit because SQLite (the test database) has **no default
  escape character**; bare `\%` is only a convention MySQL happens to
  honor.
- The empty query returns an empty collection without touching the
  database — "no-op on empty" is cheaper than a `LIKE '%%'` full scan.

Results render a `Str::excerpt()` snippet around the first body match
(falling back to the opening characters when only the title matched).
That's the whole search engine. It's O(table scan) and proud of it.

## 8. The portal layout

`layouts/portal.blade.php` is the multiple-layouts lesson: portal pages
opt out of the app shell with `#[Layout('layouts::portal')]` — no
sidebar, no team switcher, just a branded header and footer. Phase 4
(self-service portal) extends this same layout when it lands; the
`Route::has('portal.home')` guard in the header keeps the two phases
order-independent, whichever ships first.

## 9. Demo script

Seed data: `php artisan migrate:fresh --seed`, then sign in as
`sam@helpstripe.test` / `password` (Administrator). For the permission
contrast, also try `riley@helpstripe.test` / `password` (Help Desk
Staff).

1. **Permission gate** — as Riley: no "Knowledge Books" item in the
   sidebar; browsing to `/helpstripe-support/kb` directly returns 403.
   As Sam: the item is there.
2. **Create** — as Sam, open Knowledge Books → New book → "Demo Book".
   Open it, add a chapter "Walkthrough", add a page "Hello Portal".
3. **Edit** — click the page, type some Markdown including a raw
   `<script>alert(1)</script>` line. Watch the preview escape it while
   `**bold**` still renders. Flip **Published** on and Save.
4. **Publish the book** — back on the books index, publish "Demo Book"
   (a published page in a draft book stays hidden — that's the matrix).
5. **Portal** — open `/portal/kb` in a private window (no login):
   "Getting Started" and "Demo Book" are there; "Internal Runbook" is
   not. Click through book → chapter TOC → your page; the `<script>`
   shows as text.
6. **Search** — from `/portal/kb`, search "password": "Resetting Your
   Password" matches; the draft twin "Password Requirements" does not.
   Search `%`: no over-broad match — wildcards are escaped.
7. **Draft 404** — as Sam, set your page back to draft; refresh the
   portal tab: 404. The admin editor still opens it fine.
8. **Slug regeneration** — rename "Demo Book" to "Demo Handbook"; the
   old portal URL 404s, the new slug works.

## 10. Verify

```bash
php artisan test --compact --filter=KnowledgeBase   # all three suites
composer lint
composer test
```

## Future Considerations

- **Search**: `LIKE` scans every published row. The upgrade path is
  Laravel Scout (database driver first, then Meilisearch/Typesense) —
  same `published()` constraints, real relevance ranking.
- **Slug history**: renames orphan old URLs. A `slug_redirects` table
  (or sluggable's `selfHealing` URLs, which embed the model key) would
  preserve inbound links.
- **Reordering UX**: position swaps are two UPDATEs; Flux drag-and-drop
  could replace the chevron buttons without touching the data model.
