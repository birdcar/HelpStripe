<?php

use App\Enums\TeamRole;
use App\Models\Chapter;
use App\Models\KnowledgeBook;
use App\Models\Page;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Livewire\Livewire;

/**
 * Create a staff member on a fresh team, optionally holding the
 * Administrator role (which carries 'manage knowledge base').
 *
 * @return array{0: User, 1: Team}
 */
function kbStaffer(bool $administrator = true): array
{
    test()->seed(PermissionSeeder::class);

    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);

    $user->assignRole($administrator ? 'Administrator' : 'Help Desk Staff');

    return [$user, $team];
}

test('the factory chain book → chapter → page persists', function () {
    $page = Page::factory()->create();

    expect($page->chapter)->toBeInstanceOf(Chapter::class)
        ->and($page->chapter->book)->toBeInstanceOf(KnowledgeBook::class)
        ->and($page->chapter->book->team)->toBeInstanceOf(Team::class);

    $this->assertDatabaseCount('knowledge_books', 1);
    $this->assertDatabaseCount('chapters', 1);
    $this->assertDatabaseCount('pages', 1);
});

test('slugs derive from names on create', function () {
    $book = KnowledgeBook::factory()->create(['name' => 'Getting Started']);
    $chapter = Chapter::factory()->create(['knowledge_book_id' => $book->id, 'name' => 'First Steps']);
    $page = Page::factory()->create(['chapter_id' => $chapter->id, 'title' => 'Resetting Your Password']);

    expect($book->slug)->toBe('getting-started')
        ->and($chapter->slug)->toBe('first-steps')
        ->and($page->slug)->toBe('resetting-your-password');
});

test('same-titled pages in different books share a slug without collision', function () {
    $chapterA = Chapter::factory()->create();
    $chapterB = Chapter::factory()->create();

    $pageA = Page::factory()->create(['chapter_id' => $chapterA->id, 'title' => 'Introduction']);
    $pageB = Page::factory()->create(['chapter_id' => $chapterB->id, 'title' => 'Introduction']);

    // extraScope limits uniqueness to the chapter, so neither slug gets
    // a -1 suffix — the whole point of scoped slugs.
    expect($pageA->slug)->toBe('introduction')
        ->and($pageB->slug)->toBe('introduction');
});

test('duplicate titles within one chapter get a suffixed slug', function () {
    $chapter = Chapter::factory()->create();

    $first = Page::factory()->create(['chapter_id' => $chapter->id, 'title' => 'Introduction']);
    $second = Page::factory()->create(['chapter_id' => $chapter->id, 'title' => 'Introduction']);

    expect($first->slug)->toBe('introduction')
        ->and($second->slug)->toBe('introduction-1');
});

test('renaming regenerates the slug so the old slug is gone', function () {
    $book = KnowledgeBook::factory()->create(['name' => 'Getting Started']);

    $book->update(['name' => 'Quick Start']);

    expect($book->refresh()->slug)->toBe('quick-start');
    $this->assertDatabaseMissing('knowledge_books', ['slug' => 'getting-started']);
});

test('the published scopes filter draft books and pages', function () {
    $team = Team::factory()->create();
    KnowledgeBook::factory()->published()->create(['team_id' => $team->id]);
    KnowledgeBook::factory()->create(['team_id' => $team->id]);

    $chapter = Chapter::factory()->create();
    Page::factory()->published()->create(['chapter_id' => $chapter->id]);
    Page::factory()->create(['chapter_id' => $chapter->id]);

    expect(KnowledgeBook::published()->count())->toBe(1)
        ->and(Page::published()->count())->toBe(1);
});

test('position defaults to max+1 within the parent scope', function () {
    $book = KnowledgeBook::factory()->create();
    $otherBook = KnowledgeBook::factory()->create(['team_id' => $book->team_id]);

    $first = Chapter::factory()->create(['knowledge_book_id' => $book->id]);
    $second = Chapter::factory()->create(['knowledge_book_id' => $book->id]);

    // A sibling in a different book starts its own sequence — position is
    // scoped per parent, exactly like the slugs are.
    $elsewhere = Chapter::factory()->create(['knowledge_book_id' => $otherBook->id]);

    expect($first->position)->toBe(1)
        ->and($second->position)->toBe(2)
        ->and($elsewhere->position)->toBe(1);
});

test('books expose pages through chapters via hasManyThrough', function () {
    $book = KnowledgeBook::factory()->create();
    $chapterOne = Chapter::factory()->create(['knowledge_book_id' => $book->id]);
    $chapterTwo = Chapter::factory()->create(['knowledge_book_id' => $book->id]);

    Page::factory()->count(2)->create(['chapter_id' => $chapterOne->id]);
    Page::factory()->create(['chapter_id' => $chapterTwo->id]);

    // No book_id column on pages — the count proves the join walked
    // through the chapters table.
    expect($book->pages()->count())->toBe(3);
});

test('deleting a chapter cascades to its pages', function () {
    $chapter = Chapter::factory()->create();
    Page::factory()->count(2)->create(['chapter_id' => $chapter->id]);

    $chapter->delete();

    $this->assertDatabaseCount('pages', 0);
});

test('staff without the permission get 403 on the kb manager', function () {
    [$staff, $team] = kbStaffer(administrator: false);

    $this->actingAs($staff)
        ->get(route('kb.index', ['current_team' => $team->slug]))
        ->assertForbidden();
});

test('staff without the permission see no Knowledge Books nav item', function () {
    [$staff, $team] = kbStaffer(administrator: false);

    $this->actingAs($staff)
        ->get(route('dashboard', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertDontSee('Knowledge Books');
});

test('administrators can open the kb manager and see the nav item', function () {
    [$admin, $team] = kbStaffer();
    KnowledgeBook::factory()->create(['team_id' => $team->id, 'name' => 'Getting Started']);

    $this->actingAs($admin)
        ->get(route('kb.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertSee('Knowledge Books')
        ->assertSee('Getting Started');
});

test('an administrator can create a book from the index', function () {
    [$admin, $team] = kbStaffer();

    $this->actingAs($admin);

    Livewire::test('pages::kb.index')
        ->set('name', 'Getting Started')
        ->set('description', 'Your first steps')
        ->call('createBook')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('knowledge_books', [
        'team_id' => $team->id,
        'name' => 'Getting Started',
        'slug' => 'getting-started',
        'is_published' => false,
    ]);
});

test('an administrator can rename a book', function () {
    [$admin, $team] = kbStaffer();
    $book = KnowledgeBook::factory()->create(['team_id' => $team->id, 'name' => 'Getting Started']);

    $this->actingAs($admin);

    Livewire::test('pages::kb.index')
        ->call('editBook', $book->id)
        ->set('editingName', 'Quick Start')
        ->call('updateBook')
        ->assertHasNoErrors();

    expect($book->refresh())
        ->name->toBe('Quick Start')
        ->slug->toBe('quick-start');
});

test('publishing a book flips its flag from the index', function () {
    [$admin, $team] = kbStaffer();
    $book = KnowledgeBook::factory()->create(['team_id' => $team->id]);

    $this->actingAs($admin);

    Livewire::test('pages::kb.index')
        ->call('togglePublished', $book->id);

    expect($book->refresh()->is_published)->toBeTrue();
});

test('move swaps a book with its neighbor', function () {
    [$admin, $team] = kbStaffer();
    $first = KnowledgeBook::factory()->create(['team_id' => $team->id]);
    $second = KnowledgeBook::factory()->create(['team_id' => $team->id]);

    $this->actingAs($admin);

    Livewire::test('pages::kb.index')
        ->call('move', $second->id, 'up');

    expect($first->refresh()->position)->toBe(2)
        ->and($second->refresh()->position)->toBe(1);
});

test('moving the top book up is a no-op', function () {
    [$admin, $team] = kbStaffer();
    $book = KnowledgeBook::factory()->create(['team_id' => $team->id]);

    $this->actingAs($admin);

    Livewire::test('pages::kb.index')
        ->call('move', $book->id, 'up');

    expect($book->refresh()->position)->toBe(1);
});

test('actions on another team\'s book 404', function () {
    [$admin] = kbStaffer();
    $foreignBook = KnowledgeBook::factory()->create();

    $this->actingAs($admin);

    Livewire::test('pages::kb.index')
        ->call('togglePublished', $foreignBook->id)
        ->assertStatus(404);
});

test('the book manager and page editor render over http for an administrator', function () {
    [$admin, $team] = kbStaffer();
    $book = KnowledgeBook::factory()->create(['team_id' => $team->id, 'name' => 'Getting Started']);
    $chapter = Chapter::factory()->create(['knowledge_book_id' => $book->id, 'name' => 'First Steps']);
    $page = Page::factory()->create(['chapter_id' => $chapter->id, 'title' => 'Resetting Your Password']);

    $this->actingAs($admin)
        ->get(route('kb.book', ['current_team' => $team->slug, 'book' => $book->id]))
        ->assertOk()
        ->assertSee('First Steps')
        ->assertSee('Resetting Your Password');

    $this->actingAs($admin)
        ->get(route('kb.edit-page', ['current_team' => $team->slug, 'page' => $page->id]))
        ->assertOk()
        ->assertSee('Resetting Your Password');
});

test('the book manager 404s for another team\'s book', function () {
    [$admin, $team] = kbStaffer();
    $foreignBook = KnowledgeBook::factory()->create();

    $this->actingAs($admin)
        ->get(route('kb.book', ['current_team' => $team->slug, 'book' => $foreignBook->id]))
        ->assertNotFound();
});

test('chapters can be added, renamed, reordered, and deleted from the book manager', function () {
    [$admin, $team] = kbStaffer();
    $book = KnowledgeBook::factory()->create(['team_id' => $team->id]);

    $this->actingAs($admin);

    $component = Livewire::test('pages::kb.book', ['book' => $book])
        ->set('newChapterName', 'First Steps')
        ->call('addChapter')
        ->assertHasNoErrors()
        ->set('newChapterName', 'Advanced Topics')
        ->call('addChapter');

    $first = Chapter::where('name', 'First Steps')->firstOrFail();
    $second = Chapter::where('name', 'Advanced Topics')->firstOrFail();
    expect([$first->position, $second->position])->toBe([1, 2]);

    $component->call('moveChapter', $second->id, 'up');
    expect([$first->refresh()->position, $second->refresh()->position])->toBe([2, 1]);

    $component->call('startEditingChapter', $first->id)
        ->set('editingChapterName', 'Basics')
        ->call('renameChapter');
    expect($first->refresh())->name->toBe('Basics')->slug->toBe('basics');

    $component->call('deleteChapter', $first->id);
    $this->assertDatabaseMissing('chapters', ['id' => $first->id]);
});

test('pages can be added, published, reordered, and deleted from the book manager', function () {
    [$admin, $team] = kbStaffer();
    $book = KnowledgeBook::factory()->create(['team_id' => $team->id]);
    $chapter = Chapter::factory()->create(['knowledge_book_id' => $book->id]);

    $this->actingAs($admin);

    $component = Livewire::test('pages::kb.book', ['book' => $book])
        ->set(sprintf('newPageTitles.%d', $chapter->id), 'Resetting Your Password')
        ->call('addPage', $chapter->id)
        ->assertHasNoErrors();

    $page = Page::where('title', 'Resetting Your Password')->firstOrFail();
    expect($page)->is_published->toBeFalse()->position->toBe(1);

    $component->call('togglePagePublished', $page->id);
    expect($page->refresh()->is_published)->toBeTrue();

    $other = Page::factory()->create(['chapter_id' => $chapter->id]);
    $component->call('movePage', $other->id, 'up');
    expect([$page->refresh()->position, $other->refresh()->position])->toBe([2, 1]);

    $component->call('deletePage', $page->id);
    $this->assertDatabaseMissing('pages', ['id' => $page->id]);
});

test('actions on a chapter from a different book 404', function () {
    [$admin, $team] = kbStaffer();
    $book = KnowledgeBook::factory()->create(['team_id' => $team->id]);
    $otherChapter = Chapter::factory()->create();

    $this->actingAs($admin);

    Livewire::test('pages::kb.book', ['book' => $book])
        ->call('deleteChapter', $otherChapter->id)
        ->assertStatus(404);
});

test('the editor saves title, body, and draft state', function () {
    [$admin, $team] = kbStaffer();
    $chapter = Chapter::factory()->create([
        'knowledge_book_id' => KnowledgeBook::factory()->create(['team_id' => $team->id])->id,
    ]);
    $page = Page::factory()->create(['chapter_id' => $chapter->id, 'title' => 'Old Title']);

    $this->actingAs($admin);

    Livewire::test('pages::kb.edit-page', ['page' => $page])
        ->set('title', 'New Title')
        ->set('body', "## Hello\n\nWorld")
        ->set('isPublished', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($page->refresh())
        ->title->toBe('New Title')
        ->slug->toBe('new-title')
        ->body->toBe("## Hello\n\nWorld")
        ->is_published->toBeTrue();
});

test('the editor preview escapes raw html in markdown', function () {
    [$admin, $team] = kbStaffer();
    $chapter = Chapter::factory()->create([
        'knowledge_book_id' => KnowledgeBook::factory()->create(['team_id' => $team->id])->id,
    ]);
    $page = Page::factory()->create(['chapter_id' => $chapter->id]);

    $this->actingAs($admin);

    Livewire::test('pages::kb.edit-page', ['page' => $page])
        ->set('body', "<script>alert(\"xss\")</script>\n\n*fine*")
        ->assertDontSeeHtml('<script>alert("xss")</script>')
        ->assertSeeHtml('<em>fine</em>');
});

test('the editor 404s for a page on another team', function () {
    [$admin, $team] = kbStaffer();
    $foreignPage = Page::factory()->create();

    $this->actingAs($admin)
        ->get(route('kb.edit-page', ['current_team' => $team->slug, 'page' => $foreignPage->id]))
        ->assertNotFound();
});
