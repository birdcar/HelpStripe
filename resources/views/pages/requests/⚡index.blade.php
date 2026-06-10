<?php

use App\Enums\RequestStatus;
use App\Models\Category;
use App\Models\Filter;
use App\Models\Request;
use App\Models\User;
use App\Queries\RequestQueue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * The request queue — the page agents live in.
 *
 * Every filter is a #[Url]-bound property, so the browser's address bar
 * always describes exactly what the table shows. That makes views
 * shareable and bookmarkable for free — and it's why a saved Filter is
 * nothing but this same criteria array persisted as JSON.
 */
new #[Title('Queue')] class extends Component {
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $assignee = '';

    #[Url]
    public bool $urgent = false;

    #[Url]
    public string $search = '';

    /**
     * Load a saved Filter's criteria into the URL-bound properties.
     *
     * Note what this does NOT do: run a different query. Applying a
     * Filter just replays stored criteria through the same pipeline the
     * filter bar uses.
     */
    public function applyFilter(int $filterId): void
    {
        /** @var Filter $filter */
        $filter = $this->visibleFilters()->findOrFail($filterId);

        $criteria = $filter->criteria;

        $this->status = (string) ($criteria['status'] ?? '');
        $this->category = (string) ($criteria['category_id'] ?? '');
        $this->assignee = (string) ($criteria['assignee'] ?? '');
        $this->urgent = (bool) ($criteria['urgent'] ?? false);
        $this->search = (string) ($criteria['search'] ?? '');

        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('status', 'category', 'assignee', 'urgent', 'search');

        $this->resetPage();
    }

    /**
     * Hand the current criteria to the save-filter modal component.
     */
    public function openSaveFilterModal(): void
    {
        $this->dispatch('save-filter-modal:open', criteria: $this->criteria());
    }

    /**
     * Livewire calls this after any property update. Changing a filter
     * must reset pagination — page 3 of "all requests" is meaningless on
     * a freshly narrowed result set (and usually empty).
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'category', 'assignee', 'urgent', 'search'], true)) {
            $this->resetPage();
        }
    }

    /**
     * The queue itself. Eager-loading customer/category/assignee here is
     * the difference between 4 queries and 3N+1 — watch the query log in
     * Pail with these removed for the lesson (docs/tour/02).
     *
     * @return LengthAwarePaginator<int, Request>
     */
    #[Computed]
    public function requests(): LengthAwarePaginator
    {
        $query = Request::query()
            ->where('team_id', Auth::user()->current_team_id)
            ->with(['customer', 'category', 'assignee'])
            ->latest('updated_at');

        return app(RequestQueue::class)
            ->apply($query, $this->criteria(), Auth::user())
            ->paginate(15);
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Category::query()
            ->where('team_id', Auth::user()->current_team_id)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function staff(): Collection
    {
        return Auth::user()->currentTeam->members()->orderBy('name')->get();
    }

    /**
     * Saved Filters this agent can see: their own plus shared ones.
     *
     * @return Collection<int, Filter>
     */
    #[Computed]
    public function savedFilters(): Collection
    {
        return $this->visibleFilters()->orderBy('name')->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Filter>
     */
    private function visibleFilters(): \Illuminate\Database\Eloquent\Builder
    {
        return Filter::query()
            ->where('team_id', Auth::user()->current_team_id)
            ->where(function ($query) {
                $query->where('user_id', Auth::id())
                    ->orWhere('is_shared', true);
            });
    }

    /**
     * The criteria array — the queue's shared vocabulary with
     * App\Queries\RequestQueue and saved Filters.
     *
     * @return array<string, mixed>
     */
    private function criteria(): array
    {
        return [
            'status' => $this->status,
            'category_id' => $this->category,
            'assignee' => $this->assignee,
            'urgent' => $this->urgent,
            'search' => $this->search,
        ];
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="xl">{{ __('Queue') }}</flux:heading>

        <div class="flex items-center gap-2">
            <flux:dropdown position="bottom" align="end">
                <flux:button variant="outline" icon="funnel" icon:trailing="chevron-down" data-test="saved-filters-trigger">
                    {{ __('Filters') }}
                </flux:button>
                <flux:menu>
                    @forelse ($this->savedFilters as $savedFilter)
                        <flux:menu.item
                            as="button"
                            type="button"
                            wire:click="applyFilter({{ $savedFilter->id }})"
                            data-test="saved-filter-option"
                        >
                            {{ $savedFilter->name }}
                            @if ($savedFilter->is_shared)
                                <flux:badge size="sm" color="zinc" inset="top bottom">{{ __('Shared') }}</flux:badge>
                            @endif
                        </flux:menu.item>
                    @empty
                        <flux:menu.item disabled>{{ __('No saved filters yet') }}</flux:menu.item>
                    @endforelse

                    <flux:menu.separator />

                    <flux:menu.item
                        as="button"
                        type="button"
                        icon="bookmark"
                        wire:click="openSaveFilterModal"
                        data-test="save-filter-button"
                    >
                        {{ __('Save current filter…') }}
                    </flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    <div class="mt-6 flex flex-wrap items-end gap-3">
        <flux:select wire:model.live="status" :label="__('Status')" class="max-w-40" data-test="filter-status">
            <flux:select.option value="">{{ __('Any status') }}</flux:select.option>
            @foreach (App\Enums\RequestStatus::cases() as $statusOption)
                <flux:select.option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="category" :label="__('Category')" class="max-w-48" data-test="filter-category">
            <flux:select.option value="">{{ __('Any category') }}</flux:select.option>
            @foreach ($this->categories as $categoryOption)
                <flux:select.option value="{{ $categoryOption->id }}">{{ $categoryOption->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="assignee" :label="__('Assignee')" class="max-w-48" data-test="filter-assignee">
            <flux:select.option value="">{{ __('Anyone') }}</flux:select.option>
            <flux:select.option value="me">{{ __('Me') }}</flux:select.option>
            <flux:select.option value="unassigned">{{ __('Unassigned') }}</flux:select.option>
            @foreach ($this->staff as $staffOption)
                <flux:select.option value="{{ $staffOption->id }}">{{ $staffOption->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input
            wire:model.live.debounce.300ms="search"
            :label="__('Search')"
            :placeholder="__('Subject or customer email')"
            icon="magnifying-glass"
            clearable
            class="max-w-64"
            data-test="filter-search"
        />

        <flux:switch wire:model.live="urgent" :label="__('Urgent only')" data-test="filter-urgent" />

        <flux:button variant="ghost" size="sm" wire:click="clearFilters" data-test="clear-filters-button">
            {{ __('Clear') }}
        </flux:button>
    </div>

    <div class="mt-6">
        @if ($this->requests->isEmpty())
            <flux:text class="py-12 text-center text-zinc-500 dark:text-zinc-400" data-test="queue-empty-state">
                {{ __('No requests match the current filters.') }}
            </flux:text>
        @else
            <flux:table :paginate="$this->requests">
                <flux:table.columns>
                    <flux:table.column>#</flux:table.column>
                    <flux:table.column>{{ __('Subject') }}</flux:table.column>
                    <flux:table.column>{{ __('Customer') }}</flux:table.column>
                    <flux:table.column>{{ __('Category') }}</flux:table.column>
                    <flux:table.column>{{ __('Assignee') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Updated') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->requests as $queuedRequest)
                        <flux:table.row :key="$queuedRequest->id" data-test="queue-row">
                            <flux:table.cell variant="strong">{{ $queuedRequest->id }}</flux:table.cell>
                            <flux:table.cell>
                                <div class="flex items-center gap-2">
                                    {{-- current_team is passed explicitly (not left to URL::defaults):
                                         Livewire component tests render without the middleware that
                                         sets the default, and explicit is clearer anyway. --}}
                                    <flux:link :href="route('requests.show', ['current_team' => auth()->user()->currentTeam->slug, 'request' => $queuedRequest->id])" wire:navigate data-test="queue-subject-link">
                                        {{ $queuedRequest->subject }}
                                    </flux:link>
                                    @if ($queuedRequest->is_urgent)
                                        <flux:badge color="red" size="sm" inset="top bottom" data-test="urgent-badge">{{ __('Urgent') }}</flux:badge>
                                    @endif
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>{{ $queuedRequest->customer->name }}</flux:table.cell>
                            <flux:table.cell>{{ $queuedRequest->category?->name ?? '—' }}</flux:table.cell>
                            <flux:table.cell>{{ $queuedRequest->assignee?->name ?? __('Unassigned') }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge :color="$queuedRequest->status->color()" size="sm" inset="top bottom">
                                    {{ $queuedRequest->status->label() }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap">{{ $queuedRequest->updated_at->diffForHumans() }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    <livewire:pages::requests.save-filter-modal />
</section>
