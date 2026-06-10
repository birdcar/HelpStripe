<?php

use App\Models\Chapter;
use App\Models\KnowledgeBook;
use App\Models\Page;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

/*
 * One book's manager: the chapter/page tree with inline create, rename,
 * reorder, publish, and delete. Everything stays on this screen except
 * editing a page's body, which gets its own editor (⚡edit-page).
 */
new class extends Component {
    public KnowledgeBook $book;

    public string $newChapterName = '';

    /** @var array<int, string> keyed by chapter id */
    public array $newPageTitles = [];

    public ?int $editingChapterId = null;

    public string $editingChapterName = '';

    public function mount(KnowledgeBook $book): void
    {
        // The `can:` middleware proved the permission; this proves the
        // book belongs to the URL's team. 404 (not 403) so other teams'
        // book ids don't leak their existence.
        abort_unless($book->team_id === Auth::user()->current_team_id, 404);

        $this->book = $book;
    }

    public function addChapter(): void
    {
        $validated = $this->validate([
            'newChapterName' => ['required', 'string', 'max:255'],
        ]);

        $this->book->chapters()->create(['name' => $validated['newChapterName']]);

        $this->reset('newChapterName');
        unset($this->chapters);

        Flux::toast(variant: 'success', text: __('Chapter added.'));
    }

    public function startEditingChapter(Chapter $chapter): void
    {
        $this->ensureBookOwns($chapter);

        $this->editingChapterId = $chapter->id;
        $this->editingChapterName = $chapter->name;
    }

    public function renameChapter(): void
    {
        $chapter = Chapter::findOrFail($this->editingChapterId);

        $this->ensureBookOwns($chapter);

        $validated = $this->validate([
            'editingChapterName' => ['required', 'string', 'max:255'],
        ]);

        $chapter->update(['name' => $validated['editingChapterName']]);

        $this->reset('editingChapterId', 'editingChapterName');
        unset($this->chapters);

        Flux::toast(variant: 'success', text: __('Chapter renamed.'));
    }

    public function deleteChapter(Chapter $chapter): void
    {
        $this->ensureBookOwns($chapter);

        // cascadeOnDelete in the migration removes the chapter's pages —
        // the database owns hierarchy cleanup, not this component.
        $chapter->delete();

        unset($this->chapters);

        Flux::toast(variant: 'success', text: __('Chapter deleted.'));
    }

    public function moveChapter(Chapter $chapter, string $direction): void
    {
        $this->ensureBookOwns($chapter);

        $neighbor = $this->book->chapters()
            ->when(
                $direction === 'up',
                fn ($query) => $query->where('position', '<', $chapter->position)->reorder()->orderByDesc('position'),
                fn ($query) => $query->where('position', '>', $chapter->position)->reorder()->orderBy('position'),
            )
            ->first();

        if ($neighbor === null) {
            return;
        }

        DB::transaction(function () use ($chapter, $neighbor) {
            [$chapterPosition, $neighborPosition] = [$neighbor->position, $chapter->position];
            $chapter->update(['position' => $chapterPosition]);
            $neighbor->update(['position' => $neighborPosition]);
        });

        unset($this->chapters);
    }

    public function addPage(Chapter $chapter): void
    {
        $this->ensureBookOwns($chapter);

        $title = trim($this->newPageTitles[$chapter->id] ?? '');

        $this->validate(
            [sprintf('newPageTitles.%d', $chapter->id) => ['required', 'string', 'max:255']],
            attributes: [sprintf('newPageTitles.%d', $chapter->id) => __('page title')],
        );

        // New pages start as empty drafts; the editor fills the body in.
        $chapter->pages()->create(['title' => $title, 'body' => '']);

        unset($this->newPageTitles[$chapter->id], $this->chapters);

        Flux::toast(variant: 'success', text: __('Page added.'));
    }

    public function movePage(Page $page, string $direction): void
    {
        $this->ensureBookOwnsPage($page);

        $neighbor = $page->chapter->pages()
            ->when(
                $direction === 'up',
                fn ($query) => $query->where('position', '<', $page->position)->reorder()->orderByDesc('position'),
                fn ($query) => $query->where('position', '>', $page->position)->reorder()->orderBy('position'),
            )
            ->first();

        if ($neighbor === null) {
            return;
        }

        DB::transaction(function () use ($page, $neighbor) {
            [$pagePosition, $neighborPosition] = [$neighbor->position, $page->position];
            $page->update(['position' => $pagePosition]);
            $neighbor->update(['position' => $neighborPosition]);
        });

        unset($this->chapters);
    }

    public function togglePagePublished(Page $page): void
    {
        $this->ensureBookOwnsPage($page);

        $page->update(['is_published' => ! $page->is_published]);

        unset($this->chapters);

        Flux::toast(
            variant: 'success',
            text: $page->is_published ? __('Page published.') : __('Page set to draft.'),
        );
    }

    public function deletePage(Page $page): void
    {
        $this->ensureBookOwnsPage($page);

        $page->delete();

        unset($this->chapters);

        Flux::toast(variant: 'success', text: __('Page deleted.'));
    }

    /**
     * @return Collection<int, Chapter>
     */
    #[Computed]
    public function chapters(): Collection
    {
        return $this->book->chapters()->with('pages')->get();
    }

    private function ensureBookOwns(Chapter $chapter): void
    {
        abort_unless($chapter->knowledge_book_id === $this->book->id, 404);
    }

    private function ensureBookOwnsPage(Page $page): void
    {
        abort_unless($page->chapter->knowledge_book_id === $this->book->id, 404);
    }

    public function render(): mixed
    {
        return $this->view()->title($this->book->name);
    }
}; ?>

<section class="mx-auto w-full max-w-4xl">
    <div class="flex items-center justify-between">
        <div>
            <flux:text class="text-sm">
                <flux:link :href="route('kb.index', ['current_team' => auth()->user()->currentTeam->slug])" wire:navigate>{{ __('Knowledge Books') }}</flux:link>
            </flux:text>
            <div class="mt-1 flex items-center gap-2">
                <flux:heading size="xl">{{ $book->name }}</flux:heading>
                @if ($book->is_published)
                    <flux:badge color="green" size="sm">{{ __('Published') }}</flux:badge>
                @else
                    <flux:badge color="zinc" size="sm">{{ __('Draft') }}</flux:badge>
                @endif
            </div>
            @if ($book->description)
                <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">{{ $book->description }}</flux:text>
            @endif
        </div>
    </div>

    <div class="mt-6 space-y-4">
        @forelse ($this->chapters as $chapter)
            <div
                wire:key="chapter-{{ $chapter->id }}"
                class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"
                data-test="kb-chapter"
            >
                <div class="flex items-center justify-between border-b border-zinc-200 p-4 dark:border-zinc-700">
                    @if ($editingChapterId === $chapter->id)
                        <form wire:submit="renameChapter" class="flex grow items-center gap-2">
                            <flux:input wire:model="editingChapterName" size="sm" class="max-w-xs" autofocus data-test="kb-chapter-rename-input" />
                            <flux:button variant="primary" size="sm" type="submit" data-test="kb-chapter-rename-save">{{ __('Save') }}</flux:button>
                            <flux:button variant="ghost" size="sm" wire:click="$set('editingChapterId', null)">{{ __('Cancel') }}</flux:button>
                        </form>
                    @else
                        <flux:heading size="lg">{{ $chapter->name }}</flux:heading>
                    @endif

                    <div class="flex shrink-0 items-center gap-1">
                        <flux:button variant="ghost" size="sm" icon="chevron-up" wire:click="moveChapter({{ $chapter->id }}, 'up')" data-test="kb-chapter-up" :aria-label="__('Move chapter up')" />
                        <flux:button variant="ghost" size="sm" icon="chevron-down" wire:click="moveChapter({{ $chapter->id }}, 'down')" data-test="kb-chapter-down" :aria-label="__('Move chapter down')" />
                        <flux:button variant="ghost" size="sm" icon="pencil" wire:click="startEditingChapter({{ $chapter->id }})" data-test="kb-chapter-edit" :aria-label="__('Rename chapter')" />
                        <flux:button
                            variant="ghost"
                            size="sm"
                            icon="trash"
                            wire:click="deleteChapter({{ $chapter->id }})"
                            wire:confirm="{{ __('Delete this chapter and all of its pages?') }}"
                            data-test="kb-chapter-delete"
                            :aria-label="__('Delete chapter')"
                        />
                    </div>
                </div>

                <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($chapter->pages as $page)
                        <li wire:key="page-{{ $page->id }}" class="flex items-center justify-between px-4 py-2" data-test="kb-page-row">
                            <div class="flex min-w-0 items-center gap-2">
                                <flux:link :href="route('kb.edit-page', ['current_team' => auth()->user()->currentTeam->slug, 'page' => $page])" wire:navigate data-test="kb-page-link">
                                    {{ $page->title }}
                                </flux:link>

                                @if ($page->is_published)
                                    <flux:badge color="green" size="sm">{{ __('Published') }}</flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm">{{ __('Draft') }}</flux:badge>
                                @endif
                            </div>

                            <div class="flex shrink-0 items-center gap-1">
                                <flux:button variant="ghost" size="sm" icon="chevron-up" wire:click="movePage({{ $page->id }}, 'up')" data-test="kb-page-up" :aria-label="__('Move page up')" />
                                <flux:button variant="ghost" size="sm" icon="chevron-down" wire:click="movePage({{ $page->id }}, 'down')" data-test="kb-page-down" :aria-label="__('Move page down')" />
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    :icon="$page->is_published ? 'eye-slash' : 'eye'"
                                    wire:click="togglePagePublished({{ $page->id }})"
                                    data-test="kb-page-toggle-published"
                                    :aria-label="$page->is_published ? __('Set to draft') : __('Publish page')"
                                />
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    wire:click="deletePage({{ $page->id }})"
                                    wire:confirm="{{ __('Delete this page?') }}"
                                    data-test="kb-page-delete"
                                    :aria-label="__('Delete page')"
                                />
                            </div>
                        </li>
                    @endforeach

                    <li class="px-4 py-3">
                        <form wire:submit="addPage({{ $chapter->id }})" class="flex items-center gap-2">
                            <flux:input
                                wire:model="newPageTitles.{{ $chapter->id }}"
                                :placeholder="__('New page title…')"
                                size="sm"
                                class="max-w-sm"
                                data-test="kb-new-page-title"
                            />
                            <flux:button variant="filled" size="sm" type="submit" data-test="kb-new-page-submit">
                                {{ __('Add page') }}
                            </flux:button>
                        </form>
                    </li>
                </ul>
            </div>
        @empty
            <flux:text class="py-4 text-center text-zinc-500 dark:text-zinc-400" data-test="kb-no-chapters">
                {{ __('No chapters yet — add the first one below.') }}
            </flux:text>
        @endforelse
    </div>

    <form wire:submit="addChapter" class="mt-6 flex items-center gap-2">
        <flux:input wire:model="newChapterName" :placeholder="__('New chapter name…')" class="max-w-sm" data-test="kb-new-chapter-name" />
        <flux:button variant="primary" type="submit" data-test="kb-new-chapter-submit">
            {{ __('Add chapter') }}
        </flux:button>
    </form>
</section>
