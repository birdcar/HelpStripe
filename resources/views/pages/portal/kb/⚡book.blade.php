<?php

use App\Models\KnowledgeBook;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/*
 * A book's public table of contents: chapters in order, each listing its
 * *published* pages. Route model binding resolved `{book:slug}` for us —
 * but binding only proves the slug exists, not that the book is public,
 * so mount() turns drafts into 404s.
 */
new #[Layout('layouts::portal')] class extends Component {
    public KnowledgeBook $book;

    public function mount(KnowledgeBook $book): void
    {
        abort_unless($book->is_published, 404);

        $this->book = $book;
    }

    /**
     * Chapters with their published pages only. A chapter whose pages are
     * all drafts still renders — as an empty section — which is honest:
     * the structure is public, the unfinished content is not.
     *
     * @return Collection<int, \App\Models\Chapter>
     */
    #[Computed]
    public function chapters(): Collection
    {
        return $this->book->chapters()
            ->with(['pages' => fn (HasMany $query) => $query->where('is_published', true)])
            ->get();
    }

    public function render(): mixed
    {
        return $this->view()->title($this->book->name);
    }
}; ?>

<div>
    <flux:text class="text-sm">
        <flux:link :href="route('portal.kb.index')" wire:navigate>{{ __('Knowledge Base') }}</flux:link>
    </flux:text>

    <flux:heading size="xl" class="mt-1">{{ $book->name }}</flux:heading>
    @if ($book->description)
        <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">{{ $book->description }}</flux:text>
    @endif

    <div class="mt-8 space-y-8">
        @forelse ($this->chapters as $chapter)
            <section wire:key="portal-chapter-{{ $chapter->id }}" data-test="portal-kb-chapter">
                <flux:heading size="lg">{{ $chapter->name }}</flux:heading>

                <ul class="mt-3 space-y-2">
                    @forelse ($chapter->pages as $page)
                        <li wire:key="portal-page-{{ $page->id }}">
                            <flux:link
                                :href="route('portal.kb.page', ['book' => $book, 'chapter' => $chapter, 'page' => $page])"
                                wire:navigate
                                data-test="portal-kb-page-link"
                            >
                                {{ $page->title }}
                            </flux:link>
                        </li>
                    @empty
                        <li>
                            <flux:text class="text-sm text-zinc-400 dark:text-zinc-500">
                                {{ __('No articles in this chapter yet.') }}
                            </flux:text>
                        </li>
                    @endforelse
                </ul>
            </section>
        @empty
            <flux:text class="py-8 text-center text-zinc-500 dark:text-zinc-400" data-test="portal-kb-empty-toc">
                {{ __('This book has no chapters yet.') }}
            </flux:text>
        @endforelse
    </div>
</div>
