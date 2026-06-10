<?php

use App\Models\Chapter;
use App\Models\KnowledgeBook;
use App\Models\Page;
use App\Models\Team;

/**
 * Build a published book → chapter → published page chain.
 *
 * @return array{0: KnowledgeBook, 1: Chapter, 2: Page}
 */
function publishedKbChain(string $bookName = 'Getting Started', string $pageTitle = 'Resetting Your Password'): array
{
    $book = KnowledgeBook::factory()->published()->create(['name' => $bookName]);
    $chapter = Chapter::factory()->create(['knowledge_book_id' => $book->id, 'name' => 'First Steps']);
    $page = Page::factory()->published()->create([
        'chapter_id' => $chapter->id,
        'title' => $pageTitle,
        'body' => "## Steps\n\nClick *forgot password* on the sign-in page.",
    ]);

    return [$book, $chapter, $page];
}

test('the portal kb index lists published books and hides drafts', function () {
    $team = Team::factory()->create();
    KnowledgeBook::factory()->published()->create(['team_id' => $team->id, 'name' => 'Getting Started']);
    KnowledgeBook::factory()->create(['team_id' => $team->id, 'name' => 'Internal Runbook']);

    $this->get(route('portal.kb.index'))
        ->assertOk()
        ->assertSee('Getting Started')
        ->assertDontSee('Internal Runbook');
});

test('the portal kb index requires no authentication', function () {
    $this->assertGuest();

    $this->get(route('portal.kb.index'))->assertOk();
});

test('a book TOC shows chapters with published pages and hides drafts', function () {
    [$book, $chapter] = publishedKbChain();
    Page::factory()->create(['chapter_id' => $chapter->id, 'title' => 'Unfinished Draft Article']);

    $this->get(route('portal.kb.book', ['book' => $book]))
        ->assertOk()
        ->assertSee('First Steps')
        ->assertSee('Resetting Your Password')
        ->assertDontSee('Unfinished Draft Article');
});

test('a book with zero chapters renders an empty TOC', function () {
    $book = KnowledgeBook::factory()->published()->create();

    $this->get(route('portal.kb.book', ['book' => $book]))
        ->assertOk()
        ->assertSee('This book has no chapters yet.');
});

test('a draft book 404s on the portal', function () {
    $book = KnowledgeBook::factory()->create();

    $this->get(route('portal.kb.book', ['book' => $book]))
        ->assertNotFound();
});

test('a published page renders its markdown body', function () {
    [$book, $chapter, $page] = publishedKbChain();

    $this->get(route('portal.kb.page', ['book' => $book, 'chapter' => $chapter, 'page' => $page]))
        ->assertOk()
        ->assertSee('Resetting Your Password')
        ->assertSeeHtml('<h2>Steps</h2>')
        ->assertSeeHtml('<em>forgot password</em>');
});

test('raw html in a page body is escaped, never executed', function () {
    [$book, $chapter, $page] = publishedKbChain();
    $page->update(['body' => "<script>alert('xss')</script>\n\nSafe paragraph."]);

    $this->get(route('portal.kb.page', ['book' => $book, 'chapter' => $chapter, 'page' => $page]))
        ->assertOk()
        ->assertDontSeeHtml("<script>alert('xss')</script>")
        ->assertSee('Safe paragraph.');
});

test('a draft page 404s on the portal', function () {
    [$book, $chapter] = publishedKbChain();
    $draft = Page::factory()->create(['chapter_id' => $chapter->id, 'title' => 'Draft Article']);

    $this->get(route('portal.kb.page', ['book' => $book, 'chapter' => $chapter, 'page' => $draft]))
        ->assertNotFound();
});

test('a published page inside a draft book 404s on the portal', function () {
    $book = KnowledgeBook::factory()->create(); // draft
    $chapter = Chapter::factory()->create(['knowledge_book_id' => $book->id]);
    $page = Page::factory()->published()->create(['chapter_id' => $chapter->id]);

    $this->get(route('portal.kb.page', ['book' => $book, 'chapter' => $chapter, 'page' => $page]))
        ->assertNotFound();
});

test('the publish visibility matrix gates every book/page state combination', function (bool $bookPublished, bool $pagePublished, bool $visible) {
    $book = KnowledgeBook::factory()->state(['is_published' => $bookPublished])->create();
    $chapter = Chapter::factory()->create(['knowledge_book_id' => $book->id]);
    $page = Page::factory()->state(['is_published' => $pagePublished])->create(['chapter_id' => $chapter->id]);

    $this->get(route('portal.kb.page', ['book' => $book, 'chapter' => $chapter, 'page' => $page]))
        ->assertStatus($visible ? 200 : 404);
})->with([
    'published book, published page' => [true, true, true],
    'published book, draft page' => [true, false, false],
    'draft book, published page' => [false, true, false],
    'draft book, draft page' => [false, false, false],
]);

test('identical slugs in different books never cross-resolve', function () {
    [$bookA, $chapterA, $pageA] = publishedKbChain('Getting Started', 'Introduction');

    $bookB = KnowledgeBook::factory()->published()->create(['name' => 'Billing FAQ']);
    $chapterB = Chapter::factory()->create(['knowledge_book_id' => $bookB->id, 'name' => 'First Steps']);
    $pageB = Page::factory()->published()->create([
        'chapter_id' => $chapterB->id,
        'title' => 'Introduction',
        'body' => 'Billing version of the introduction.',
    ]);

    // Same chapter slug, same page slug — only the book segment differs.
    expect($pageA->slug)->toBe($pageB->slug)
        ->and($chapterA->slug)->toBe($chapterB->slug);

    $this->get("/portal/kb/{$bookB->slug}/{$chapterB->slug}/{$pageB->slug}")
        ->assertOk()
        ->assertSee('Billing version of the introduction.');
});

test('a page slug requested under the wrong book 404s via scoped bindings', function () {
    [$bookA] = publishedKbChain('Getting Started', 'Introduction');

    $bookB = KnowledgeBook::factory()->published()->create(['name' => 'Billing FAQ']);
    $chapterB = Chapter::factory()->create(['knowledge_book_id' => $bookB->id, 'name' => 'Invoices']);
    $pageB = Page::factory()->published()->create(['chapter_id' => $chapterB->id, 'title' => 'Reading Your Invoice']);

    // Book A has no "invoices" chapter — scopeBindings() resolves the
    // chapter through $bookA->chapters() and finds nothing.
    $this->get("/portal/kb/{$bookA->slug}/{$chapterB->slug}/{$pageB->slug}")
        ->assertNotFound();
});

test('a renamed page is gone from its old slug', function () {
    [$book, $chapter, $page] = publishedKbChain();
    $oldSlug = $page->slug;

    $page->update(['title' => 'Recovering Account Access']);

    $this->get("/portal/kb/{$book->slug}/{$chapter->slug}/{$oldSlug}")
        ->assertNotFound();

    $this->get("/portal/kb/{$book->slug}/{$chapter->slug}/{$page->refresh()->slug}")
        ->assertOk();
});
