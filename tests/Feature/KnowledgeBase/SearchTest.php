<?php

use App\Models\Chapter;
use App\Models\KnowledgeBook;
use App\Models\Page;
use Livewire\Livewire;

/**
 * One published chapter to hang search fixtures on.
 */
function searchableChapter(): Chapter
{
    return Chapter::factory()->create([
        'knowledge_book_id' => KnowledgeBook::factory()->published()->create()->id,
    ]);
}

test('search matches published page titles', function () {
    $chapter = searchableChapter();
    Page::factory()->published()->create(['chapter_id' => $chapter->id, 'title' => 'Resetting Your Password']);
    Page::factory()->published()->create(['chapter_id' => $chapter->id, 'title' => 'Reading Your Invoice']);

    $this->get(route('portal.kb.search', ['q' => 'password']))
        ->assertOk()
        ->assertSee('Resetting Your Password')
        ->assertDontSee('Reading Your Invoice');
});

test('search matches inside page bodies', function () {
    $chapter = searchableChapter();
    Page::factory()->published()->create([
        'chapter_id' => $chapter->id,
        'title' => 'Account Recovery',
        'body' => 'If you lost your password, use the recovery flow.',
    ]);

    Livewire::test('pages::portal.kb.search')
        ->set('q', 'password')
        ->assertSee('Account Recovery');
});

test('search excludes draft pages even when the term matches', function () {
    $chapter = searchableChapter();
    Page::factory()->published()->create(['chapter_id' => $chapter->id, 'title' => 'Password Basics']);
    Page::factory()->create(['chapter_id' => $chapter->id, 'title' => 'Password Internals (Draft)']);

    $this->get(route('portal.kb.search', ['q' => 'password']))
        ->assertOk()
        ->assertSee('Password Basics')
        ->assertDontSee('Password Internals (Draft)');
});

test('search excludes published pages whose book is a draft', function () {
    $draftBookChapter = Chapter::factory()->create([
        'knowledge_book_id' => KnowledgeBook::factory()->create()->id,
    ]);
    Page::factory()->published()->create([
        'chapter_id' => $draftBookChapter->id,
        'title' => 'Password Secrets',
    ]);

    $this->get(route('portal.kb.search', ['q' => 'password']))
        ->assertOk()
        ->assertDontSee('Password Secrets');
});

test('an empty query renders the prompt and no results', function () {
    $chapter = searchableChapter();
    Page::factory()->published()->create(['chapter_id' => $chapter->id, 'title' => 'Some Article']);

    $this->get(route('portal.kb.search'))
        ->assertOk()
        ->assertSee('Type something to search')
        ->assertDontSee('Some Article');
});

test('like wildcards in the query are treated literally', function () {
    $chapter = searchableChapter();
    Page::factory()->published()->create([
        'chapter_id' => $chapter->id,
        'title' => 'Discounts Explained',
        'body' => 'Enter a literal 50% coupon at checkout.',
    ]);
    Page::factory()->published()->create([
        'chapter_id' => $chapter->id,
        'title' => 'Unrelated Article',
        'body' => 'Nothing about percentages here.',
    ]);

    // "%" would match every row if passed through unescaped.
    Livewire::test('pages::portal.kb.search')
        ->set('q', '50%')
        ->assertSee('Discounts Explained')
        ->assertDontSee('Unrelated Article');

    Livewire::test('pages::portal.kb.search')
        ->set('q', '_____________________________')
        ->assertDontSee('Discounts Explained')
        ->assertDontSee('Unrelated Article');
});

test('a result links to the page and shows a body excerpt', function () {
    $chapter = searchableChapter();
    $page = Page::factory()->published()->create([
        'chapter_id' => $chapter->id,
        'title' => 'Resetting Your Password',
        'body' => 'Open the sign-in page, choose forgot password, and follow the email link.',
    ]);

    $this->get(route('portal.kb.search', ['q' => 'forgot password']))
        ->assertOk()
        ->assertSee('Resetting Your Password')
        ->assertSee('follow the email link')
        ->assertSee(route('portal.kb.page', [
            'book' => $page->chapter->book,
            'chapter' => $page->chapter,
            'page' => $page,
        ]));
});
