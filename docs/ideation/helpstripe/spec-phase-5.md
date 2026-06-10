# Implementation Spec: HelpStripe - Phase 5: Knowledge Base

**Contract**: ./contract.md
**Estimated Effort**: M

## Technical Approach

A faithful HelpSpot-shaped knowledge base: **Knowledge Books → Chapters → Pages**, each level ordered and sluggable, with draft/published state on books and pages. Two surfaces: an admin manager inside the app (gated by the `manage knowledge base` permission from Phase 1) and public portal browsing with a simple LIKE search. Page bodies are Markdown rendered server-side with `Str::markdown()`.

The Laravel curriculum here: nested relations and `hasManyThrough`, `spatie/laravel-sluggable`, nested route model binding with scoped bindings (`->scopeBindings()`), authorization via permissions (contrast with Phase 2's membership-based policy), and the publish/draft visibility pattern via query scopes.

This phase depends only on Phase 1 and is parallelizable with phases 2–4. The only portal coupling is additive: the portal home's KB teaser (Phase 4) lights up when these routes exist; if Phase 4 hasn't run yet, the portal KB routes still work standalone under the portal layout — whichever phase lands second completes the wiring, both specs carry the same `Route::has()` guard note.

## Feedback Strategy

**Inner-loop command**: `php artisan test --compact --filter=KnowledgeBase`

**Playground**: Pest tests for model/scope logic; browser at `/{team}/kb` (admin) and `/portal/kb` (public) with seeded content.

**Why this approach**: CRUD + hierarchy logic tests fast; the two UIs are thin over it.

## File Changes

### New Files

| File Path | Purpose |
| --- | --- |
| `app/Models/KnowledgeBook.php` + migration + factory | Book: name, slug, description, is_published, position |
| `app/Models/Chapter.php` + migration + factory | Chapter: book FK, name, slug, position |
| `app/Models/Page.php` + migration + factory | Page: chapter FK, title, slug, body (markdown), is_published, position |
| `resources/views/pages/kb/⚡index.blade.php` | Admin: books list + create/edit/publish |
| `resources/views/pages/kb/⚡book.blade.php` | Admin: one book — chapters + pages tree, inline create/reorder/edit |
| `resources/views/pages/kb/⚡edit-page.blade.php` | Admin: page editor — title, markdown body, draft toggle, live preview |
| `resources/views/pages/portal/kb/⚡index.blade.php` | Portal: published books grid + search box |
| `resources/views/pages/portal/kb/⚡book.blade.php` | Portal: book TOC (chapters → pages) |
| `resources/views/pages/portal/kb/⚡page.blade.php` | Portal: rendered page |
| `resources/views/pages/portal/kb/⚡search.blade.php` | Portal: LIKE search results |
| `tests/Feature/KnowledgeBase/AdminCrudTest.php` | Book/chapter/page CRUD + permission gate |
| `tests/Feature/KnowledgeBase/PortalBrowsingTest.php` | Browsing, drafts hidden, nested slugs resolve |
| `tests/Feature/KnowledgeBase/SearchTest.php` | Search hits titles + bodies, excludes drafts |
| `docs/tour/05-knowledge-base.md` | Tour doc: nested relations, slugs, scoped bindings, scopes, markdown + demo script |

### Modified Files

| File Path | Changes |
| --- | --- |
| `composer.json` | `composer require spatie/laravel-sluggable` |
| `routes/web.php` | Admin `kb` routes in `{current_team}` group; public `portal/kb/*` routes |
| `resources/views/layouts/app/sidebar.blade.php` | "Knowledge Books" nav item (visible with permission) |
| `database/seeders/DemoSeeder.php` | 2 books ("Getting Started" published, "Internal Runbook" draft), ~3 chapters, ~10 pages incl. drafts |
| `resources/views/pages/portal/⚡home.blade.php` | Activate KB teaser (if Phase 4 already shipped) |

## Implementation Details

### Models + hierarchy

**Pattern to follow**: Phase 1 models; sluggable per spatie docs (Boost `search-docs` `['sluggable']`)

**Overview**: Three models with `HasSlug` (slug from name/title, unique per parent scope). `KnowledgeBook` hasMany Chapters (ordered by position), `hasManyThrough` Pages. Scopes: `published()` on books and pages. Position columns default to max+1 on create.

**Key decisions**:
- Slug uniqueness scoped per parent (`extraScope` on chapters/pages) so two books can both have an "Introduction" — the slug-scoping lesson.
- Reordering is simple position swap buttons (up/down), not drag-and-drop — keeps the phase honest; flux drag is a future nicety.
- Drafts: unpublished book hides all its pages on the portal regardless of page state.

**Feedback loop**:
- **Playground**: `AdminCrudTest` smoke (factory chain book→chapter→page persists).
- **Experiment**: same-name pages in two books get same slug without collision; published scope matrix (book draft/page published etc.); position ordering after up/down.
- **Check command**: `php artisan test --compact --filter=AdminCrudTest`

### Admin manager UIs

**Pattern to follow**: `resources/views/pages/teams/⚡index.blade.php` + modal pattern from `⚡create-team-modal.blade.php`

**Overview**: Three SFCs: books index (cards with publish badge + counts), book detail (chapter/page tree, inline add forms, publish toggles, reorder buttons), page editor (two-pane: markdown textarea, `Str::markdown` preview via a computed property, draft toggle, save toast).

**Key decisions**:
- Permission gate: routes behind `can:manage knowledge base` middleware + `@can` on the sidebar item — the spatie-permission-in-practice lesson.
- Preview escapes raw HTML in markdown (`Str::markdown($body, ['html_input' => 'escape'])`) — the XSS lesson, called out in the tour doc.

**Feedback loop**:
- **Playground**: browser as admin (has permission) and as staff (doesn't).
- **Experiment**: staff user gets 403 on `/kb` and no sidebar item; create book→chapter→page→publish→appears on portal.
- **Check command**: `php artisan test --compact --filter=KnowledgeBase`

### Portal browsing + search

**Pattern to follow**: Phase 4 portal pages (layout + public routes)

**Overview**: Nested slug routes with scoped bindings:

```php
Route::livewire('portal/kb', 'pages::portal.kb.index')->name('portal.kb.index');
Route::livewire('portal/kb/{book:slug}', 'pages::portal.kb.book')->scopeBindings();
Route::livewire('portal/kb/{book:slug}/{chapter:slug}/{page:slug}', 'pages::portal.kb.page')->scopeBindings();
```

Search: one input on the KB index; results from `Page::published()->whereHas('chapter.book', published)->where(title/body LIKE %q%)` with naive `str()->excerpt()` highlighting.

**Key decisions**:
- `scopeBindings()` makes child slugs resolve within their parent — *the* route-model-binding lesson of the repo.
- LIKE search is deliberately naive; tour doc names Scout as the upgrade path (Future Considerations).

**Feedback loop**:
- **Playground**: `PortalBrowsingTest` / `SearchTest`; browser.
- **Experiment**: draft page 404s on portal but renders in admin; same-slug page in other book doesn't cross-resolve; search "password" finds seeded page, excludes draft twin; empty query no-ops.
- **Check command**: `php artisan test --compact --filter=PortalBrowsingTest`

### Tour doc 05

Covers: hasManyThrough, sluggable, scoped bindings, query scopes, markdown rendering + escaping, permission middleware vs policies. Demo script: as admin create + publish a page, view it on the portal, search for it, flip to draft, watch it 404.

## Data Model

```php
// knowledge_books: id, team_id FK, name, slug (unique per team), description nullable, is_published bool default false, position int, timestamps
// chapters: id, knowledge_book_id FK cascade, name, slug (unique per book), position, timestamps
// pages: id, chapter_id FK cascade, title, slug (unique per chapter), body text, is_published bool default false, position, timestamps
// index: pages(title), pages(is_published)
```

## Testing Requirements

Covered per component. **Key edge cases**: book with zero chapters renders empty TOC; chapter deleted cascades pages; slug regeneration on rename keeps old slug 404 (no redirect — named in doc); markdown with raw `<script>` renders escaped.

### Manual Testing

- [ ] Demo script start-to-finish
- [ ] Portal KB readable on mobile width

## Error Handling

| Error Scenario | Handling Strategy |
| --- | --- |
| Slug collision on rename | Sluggable regenerates with suffix; test asserts |
| Unpublished content fetched directly | 404 via published scopes in portal queries |
| No permission | 403 middleware + hidden nav |

## Failure Modes

| Component | Failure Mode | Trigger | Impact | Mitigation |
| --- | --- | --- | --- | --- |
| Markdown render | Stored XSS | malicious HTML in body | script on portal | `html_input => escape`; test with `<script>` fixture |
| Scoped binding | Cross-parent resolution | same slug in two books | wrong page served | `scopeBindings()` + explicit test |
| Search | LIKE wildcard injection | `%` in query | over-broad match | escape `%`/`_` in the term; test |
| Publish cascade | Orphan-visible pages | published page in draft book | draft content public | visibility checks book AND page; matrix test |

## Validation Commands

```bash
composer lint
php artisan test --compact --filter=KnowledgeBase
composer test
./init.sh
```

## Rollout Considerations

None. Update `feature_list.json` + `progress.md` with evidence on completion.
