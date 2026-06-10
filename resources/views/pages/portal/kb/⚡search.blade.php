<?php

use App\Models\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Deliberately naive LIKE search — title and body, published content
 * only. Good enough for a help center's hundreds of pages; the upgrade
 * path (Laravel Scout + a real engine) is named in the tour doc's
 * Future Considerations.
 */
new #[Layout('layouts::portal')] #[Title('Search')] class extends Component {
    #[Url]
    public string $q = '';

    public function search(): void
    {
        // wire:model binds $q and #[Url] mirrors it to ?q=… — submitting
        // just re-renders with the new term; nothing else to do.
        unset($this->results);
    }

    /**
     * @return Collection<int, Page>
     */
    #[Computed]
    public function results(): Collection
    {
        $term = trim($this->q);

        // Empty query no-ops: render the prompt, hit the database never.
        if ($term === '') {
            return new Collection;
        }

        // LIKE wildcards in user input would silently broaden the match
        // ("%" finds everything). Escape %, _ and the escape char itself,
        // and say ESCAPE explicitly — SQLite has no default escape
        // character, so `\%` alone would not be portable.
        $like = '%'.addcslashes($term, '\%_').'%';

        return Page::query()
            ->published()
            ->whereHas(
                'chapter.book',
                fn (Builder $query) => $query->where('is_published', true),
            )
            ->where(function (Builder $query) use ($like) {
                $query->whereRaw('title LIKE ? ESCAPE ?', [$like, '\\'])
                    ->orWhereRaw('body LIKE ? ESCAPE ?', [$like, '\\']);
            })
            ->with('chapter.book')
            ->orderBy('title')
            ->get();
    }

    /**
     * A contextual snippet around the first match in the body — naive
     * highlighting via Str::excerpt(). Falls back to the opening of the
     * body when the term only matched the title.
     */
    public function excerptFor(Page $page): string
    {
        return str($page->body)->excerpt(trim($this->q), ['radius' => 80])
            ?? str($page->body)->limit(160)->toString();
    }
}; ?>

<div>
    <flux:text class="text-sm">
        <flux:link :href="route('portal.kb.index')" wire:navigate>{{ __('Knowledge Base') }}</flux:link>
    </flux:text>

    <flux:heading size="xl" class="mt-1">{{ __('Search') }}</flux:heading>

    <form wire:submit="search" class="mt-6">
        <flux:input
            wire:model="q"
            type="search"
            :placeholder="__('Search the knowledge base…')"
            icon="magnifying-glass"
            autofocus
            data-test="portal-kb-search-input"
        />
    </form>

    <div class="mt-8 space-y-4">
        @if (trim($q) === '')
            <flux:text class="py-4 text-center text-zinc-500 dark:text-zinc-400" data-test="portal-kb-search-prompt">
                {{ __('Type something to search the knowledge base.') }}
            </flux:text>
        @else
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400" data-test="portal-kb-search-count">
                {{ trans_choice(':count result|:count results', $this->results->count(), ['count' => $this->results->count()]) }}
                {{ __('for') }} “{{ trim($q) }}”
            </flux:text>

            @forelse ($this->results as $page)
                <div
                    wire:key="result-{{ $page->id }}"
                    class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900"
                    data-test="portal-kb-search-result"
                >
                    <flux:link
                        :href="route('portal.kb.page', ['book' => $page->chapter->book, 'chapter' => $page->chapter, 'page' => $page])"
                        wire:navigate
                        class="font-medium"
                    >
                        {{ $page->title }}
                    </flux:link>

                    <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $page->chapter->book->name }} · {{ $page->chapter->name }}
                    </flux:text>

                    <flux:text class="mt-2 text-sm">{{ $this->excerptFor($page) }}</flux:text>
                </div>
            @empty
                <flux:text class="py-4 text-center text-zinc-500 dark:text-zinc-400" data-test="portal-kb-search-none">
                    {{ __('No articles matched. Try a different phrase.') }}
                </flux:text>
            @endforelse
        @endif
    </div>
</div>
