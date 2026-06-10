<?php

use App\Models\Page;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

/*
 * Page editor — two panes: Markdown source on the left, rendered preview
 * on the right. The preview is a #[Computed] property over the unsaved
 * body, so it tracks keystrokes (debounced) without persisting anything.
 *
 * Both the preview and the portal render with `html_input => escape`:
 * what the author sees here is exactly what customers get, including the
 * neutralization of any raw HTML.
 */
new class extends Component {
    public Page $page;

    public string $title = '';

    public string $body = '';

    public bool $isPublished = false;

    public function mount(Page $page): void
    {
        abort_unless(
            $page->chapter->book->team_id === Auth::user()->current_team_id,
            404,
        );

        $this->page = $page;
        $this->title = $page->title;
        $this->body = $page->body;
        $this->isPublished = $page->is_published;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'isPublished' => ['boolean'],
        ]);

        // A title change regenerates the slug (old portal URL 404s) —
        // same lesson as renaming a book.
        $this->page->update([
            'title' => $validated['title'],
            'body' => $validated['body'] ?? '',
            'is_published' => $validated['isPublished'],
        ]);

        Flux::toast(variant: 'success', text: __('Page saved.'));
    }

    #[Computed]
    public function preview(): HtmlString
    {
        return new HtmlString(
            Str::markdown($this->body, ['html_input' => 'escape'])
        );
    }

    public function render(): mixed
    {
        return $this->view()->title($this->page->title);
    }
}; ?>

<section class="mx-auto w-full max-w-6xl">
    <flux:text class="text-sm">
        <flux:link :href="route('kb.index', ['current_team' => auth()->user()->currentTeam->slug])" wire:navigate>{{ __('Knowledge Books') }}</flux:link>
        <span class="mx-1">/</span>
        <flux:link :href="route('kb.book', ['current_team' => auth()->user()->currentTeam->slug, 'book' => $page->chapter->book])" wire:navigate>{{ $page->chapter->book->name }}</flux:link>
        <span class="mx-1">/</span>
        {{ $page->chapter->name }}
    </flux:text>

    <form wire:submit="save" class="mt-4 space-y-6">
        <div class="flex items-end justify-between gap-4">
            <div class="grow">
                <flux:input wire:model="title" :label="__('Title')" type="text" required data-test="kb-page-title" />
            </div>

            <div class="flex shrink-0 items-center gap-4 pb-1">
                <flux:switch wire:model="isPublished" :label="__('Published')" data-test="kb-page-published" />
                <flux:button variant="primary" type="submit" data-test="kb-page-save">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <flux:textarea
                wire:model.live.debounce.300ms="body"
                :label="__('Body (Markdown)')"
                rows="20"
                class="font-mono"
                data-test="kb-page-body"
            />

            <div>
                <flux:text class="mb-2 block text-sm font-medium">{{ __('Preview') }}</flux:text>
                <div
                    class="prose prose-zinc dark:prose-invert h-full min-h-[20rem] max-w-none rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900"
                    data-test="kb-page-preview"
                >
                    {!! $this->preview !!}
                </div>
            </div>
        </div>
    </form>
</section>
