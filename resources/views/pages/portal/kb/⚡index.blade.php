<?php

use App\Models\KnowledgeBook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Public knowledge base index: published books only, plus the search box.
 *
 * No team in the URL and no auth — the portal serves THE installation's
 * published content. Compare the admin ⚡index, which scopes to the
 * signed-in user's current team and shows drafts.
 */
new #[Layout('layouts::portal')] #[Title('Knowledge Base')] class extends Component {
    /**
     * @return Collection<int, KnowledgeBook>
     */
    #[Computed]
    public function books(): Collection
    {
        return KnowledgeBook::published()
            ->withCount([
                'pages' => fn (Builder $query) => $query->where('is_published', true),
            ])
            ->orderBy('position')
            ->get();
    }
}; ?>

<div>
    <flux:heading size="xl">{{ __('Knowledge Base') }}</flux:heading>
    <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
        {{ __('Guides and answers, no ticket required.') }}
    </flux:text>

    {{-- A plain GET form, on purpose: search results live at a shareable,
         bookmarkable URL (/portal/kb/search?q=…) instead of in ephemeral
         component state. --}}
    <form action="{{ route('portal.kb.search') }}" method="GET" class="mt-6">
        <flux:input
            name="q"
            type="search"
            :placeholder="__('Search the knowledge base…')"
            icon="magnifying-glass"
            data-test="portal-kb-search-input"
        />
    </form>

    <div class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2">
        @forelse ($this->books as $book)
            <a
                href="{{ route('portal.kb.book', ['book' => $book]) }}"
                wire:navigate
                wire:key="portal-book-{{ $book->id }}"
                class="rounded-lg border border-zinc-200 bg-white p-5 transition hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600"
                data-test="portal-kb-book-card"
            >
                <flux:heading size="lg">{{ $book->name }}</flux:heading>
                @if ($book->description)
                    <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">{{ $book->description }}</flux:text>
                @endif
                <flux:text class="mt-3 text-sm text-zinc-400 dark:text-zinc-500">
                    {{ trans_choice(':count article|:count articles', $book->pages_count, ['count' => $book->pages_count]) }}
                </flux:text>
            </a>
        @empty
            <flux:text class="col-span-full py-8 text-center text-zinc-500 dark:text-zinc-400" data-test="portal-kb-empty">
                {{ __('Nothing published yet — check back soon.') }}
            </flux:text>
        @endforelse
    </div>
</div>
