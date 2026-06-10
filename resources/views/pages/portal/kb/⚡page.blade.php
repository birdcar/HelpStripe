<?php

use App\Models\Chapter;
use App\Models\KnowledgeBook;
use App\Models\Page;
use Livewire\Attributes\Layout;
use Livewire\Component;

/*
 * A rendered article. The route declared scopeBindings(), so by the time
 * mount() runs, Laravel has already proven the hierarchy: `{chapter:slug}`
 * was resolved through $book->chapters() and `{page:slug}` through
 * $chapter->pages(). A page slug that exists in a *different* book 404s
 * during binding — mount() never sees it.
 *
 * What binding can't prove is visibility: both the book AND the page must
 * be published. A published page inside a draft book stays hidden.
 */
new #[Layout('layouts::portal')] class extends Component {
    public KnowledgeBook $book;

    public Chapter $chapter;

    public Page $page;

    public function mount(KnowledgeBook $book, Chapter $chapter, Page $page): void
    {
        abort_unless($book->is_published && $page->is_published, 404);

        $this->book = $book;
        $this->chapter = $chapter;
        $this->page = $page;
    }

    public function render(): mixed
    {
        return $this->view()->title($this->page->title);
    }
}; ?>

<article>
    <flux:text class="text-sm">
        <flux:link :href="route('portal.kb.index')" wire:navigate>{{ __('Knowledge Base') }}</flux:link>
        <span class="mx-1">/</span>
        <flux:link :href="route('portal.kb.book', ['book' => $book])" wire:navigate>{{ $book->name }}</flux:link>
        <span class="mx-1">/</span>
        {{ $chapter->name }}
    </flux:text>

    <flux:heading size="xl" class="mt-1">{{ $page->title }}</flux:heading>

    {{-- renderedBody() escapes raw HTML before this unescaped echo — the
         {!! !!} is safe *because* of html_input => escape, not despite it. --}}
    <div class="prose prose-zinc dark:prose-invert mt-6 max-w-none" data-test="portal-kb-page-body">
        {!! $page->renderedBody() !!}
    </div>
</article>
