<?php

use App\Models\KnowledgeBook;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Knowledge Books index — the admin manager's front door.
 *
 * The route already sits behind `can:manage knowledge base`, so anyone
 * who reaches this component holds the permission; the per-action team
 * guard below protects against a different attack — passing another
 * team's book id to a Livewire action.
 */
new #[Title('Knowledge Books')] class extends Component {
    public string $name = '';

    public string $description = '';

    public ?int $editingBookId = null;

    public string $editingName = '';

    public string $editingDescription = '';

    public function createBook(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        KnowledgeBook::create([
            'team_id' => Auth::user()->current_team_id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?: null,
        ]);

        $this->reset('name', 'description');

        Flux::modal('create-book')->close();
        Flux::toast(variant: 'success', text: __('Book created.'));
    }

    public function editBook(KnowledgeBook $book): void
    {
        $this->ensureCurrentTeamOwns($book);

        $this->editingBookId = $book->id;
        $this->editingName = $book->name;
        $this->editingDescription = $book->description ?? '';

        Flux::modal('edit-book')->show();
    }

    public function updateBook(): void
    {
        $book = KnowledgeBook::findOrFail($this->editingBookId);

        $this->ensureCurrentTeamOwns($book);

        $validated = $this->validate([
            'editingName' => ['required', 'string', 'max:255'],
            'editingDescription' => ['nullable', 'string', 'max:1000'],
        ]);

        // Renaming regenerates the slug (sluggable's default): the old
        // portal URL 404s afterwards. Deliberate — see the tour doc.
        $book->update([
            'name' => $validated['editingName'],
            'description' => $validated['editingDescription'] ?: null,
        ]);

        $this->reset('editingBookId', 'editingName', 'editingDescription');

        Flux::modal('edit-book')->close();
        Flux::toast(variant: 'success', text: __('Book updated.'));
    }

    public function togglePublished(KnowledgeBook $book): void
    {
        $this->ensureCurrentTeamOwns($book);

        $book->update(['is_published' => ! $book->is_published]);

        Flux::toast(
            variant: 'success',
            text: $book->is_published ? __('Book published.') : __('Book unpublished.'),
        );
    }

    /**
     * Reorder by swapping positions with the neighbor — the simple,
     * honest version of ordering. No drag-and-drop; two UPDATEs.
     */
    public function move(KnowledgeBook $book, string $direction): void
    {
        $this->ensureCurrentTeamOwns($book);

        $neighbor = KnowledgeBook::query()
            ->where('team_id', $book->team_id)
            ->when(
                $direction === 'up',
                fn ($query) => $query->where('position', '<', $book->position)->orderByDesc('position'),
                fn ($query) => $query->where('position', '>', $book->position)->orderBy('position'),
            )
            ->first();

        if ($neighbor === null) {
            return; // Already at the edge of the list.
        }

        // Two UPDATEs, one transaction: a concurrent reorder must never
        // observe (or leave behind) two books on the same position.
        DB::transaction(function () use ($book, $neighbor) {
            [$bookPosition, $neighborPosition] = [$neighbor->position, $book->position];
            $book->update(['position' => $bookPosition]);
            $neighbor->update(['position' => $neighborPosition]);
        });
    }

    /**
     * @return Collection<int, KnowledgeBook>
     */
    #[Computed]
    public function books(): Collection
    {
        return KnowledgeBook::query()
            ->where('team_id', Auth::user()->current_team_id)
            ->withCount(['chapters', 'pages'])
            ->orderBy('position')
            ->get();
    }

    private function ensureCurrentTeamOwns(KnowledgeBook $book): void
    {
        abort_unless($book->team_id === Auth::user()->current_team_id, 404);
    }
}; ?>

<section class="mx-auto w-full max-w-4xl">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Knowledge Books') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                {{ __('Books group chapters; chapters group pages. Published books appear on the portal.') }}
            </flux:text>
        </div>

        <flux:modal.trigger name="create-book">
            <flux:button variant="primary" icon="plus" data-test="kb-new-book-button">
                {{ __('New book') }}
            </flux:button>
        </flux:modal.trigger>
    </div>

    <div class="mt-6 space-y-3">
        @forelse ($this->books as $book)
            <div
                wire:key="book-{{ $book->id }}"
                class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900"
                data-test="kb-book-row"
            >
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <flux:link :href="route('kb.book', ['current_team' => auth()->user()->currentTeam->slug, 'book' => $book])" wire:navigate class="font-medium" data-test="kb-book-link">
                            {{ $book->name }}
                        </flux:link>

                        @if ($book->is_published)
                            <flux:badge color="green" size="sm">{{ __('Published') }}</flux:badge>
                        @else
                            <flux:badge color="zinc" size="sm">{{ __('Draft') }}</flux:badge>
                        @endif
                    </div>

                    <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                        {{ trans_choice(':count chapter|:count chapters', $book->chapters_count, ['count' => $book->chapters_count]) }}
                        ·
                        {{ trans_choice(':count page|:count pages', $book->pages_count, ['count' => $book->pages_count]) }}
                        @if ($book->description)
                            · {{ $book->description }}
                        @endif
                    </flux:text>
                </div>

                <div class="flex shrink-0 items-center gap-1">
                    <flux:button variant="ghost" size="sm" icon="chevron-up" wire:click="move({{ $book->id }}, 'up')" data-test="kb-book-up" :aria-label="__('Move up')" />
                    <flux:button variant="ghost" size="sm" icon="chevron-down" wire:click="move({{ $book->id }}, 'down')" data-test="kb-book-down" :aria-label="__('Move down')" />
                    <flux:button variant="ghost" size="sm" icon="pencil" wire:click="editBook({{ $book->id }})" data-test="kb-book-edit" :aria-label="__('Edit book')" />
                    <flux:button
                        variant="ghost"
                        size="sm"
                        :icon="$book->is_published ? 'eye-slash' : 'eye'"
                        wire:click="togglePublished({{ $book->id }})"
                        data-test="kb-book-toggle-published"
                        :aria-label="$book->is_published ? __('Unpublish') : __('Publish')"
                    />
                </div>
            </div>
        @empty
            <flux:text class="py-8 text-center text-zinc-500 dark:text-zinc-400" data-test="kb-empty">
                {{ __('No books yet. Create the first one to start your knowledge base.') }}
            </flux:text>
        @endforelse
    </div>

    <flux:modal name="create-book" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
        <form wire:submit="createBook" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Create a knowledge book') }}</flux:heading>
                <flux:subheading>{{ __('Books start as drafts — publish when the content is ready.') }}</flux:subheading>
            </div>

            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus data-test="kb-create-name" />
            <flux:textarea wire:model="description" :label="__('Description')" rows="2" data-test="kb-create-description" />

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="primary" type="submit" data-test="kb-create-submit">
                    {{ __('Create book') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="edit-book" focusable class="max-w-lg">
        <form wire:submit="updateBook" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Edit book') }}</flux:heading>
                <flux:subheading>{{ __('Renaming regenerates the slug — old portal links will stop working.') }}</flux:subheading>
            </div>

            <flux:input wire:model="editingName" :label="__('Name')" type="text" required data-test="kb-edit-name" />
            <flux:textarea wire:model="editingDescription" :label="__('Description')" rows="2" data-test="kb-edit-description" />

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="primary" type="submit" data-test="kb-edit-submit">
                    {{ __('Save changes') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
