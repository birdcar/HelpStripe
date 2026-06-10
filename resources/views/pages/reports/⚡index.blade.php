<?php

use App\Queries\Reports\AgentPerformance;
use App\Queries\Reports\CategoryPerformance;
use App\Queries\Reports\QueueSnapshot;
use App\Queries\Reports\RequestVolume;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * The reporting page — HelpSpot's sixth pillar.
 *
 * Everything here is read-only aggregation over the data Phases 1–2 already
 * produce (created_at, first_responded_at, resolved_at, assigned_to, category
 * SLA targets). The component itself owns no query logic: each block delegates
 * to a query object in App\Queries\Reports, the same pattern RequestQueue
 * established. The view just renders shaped arrays.
 *
 * A single #[Url] `range` property (7 | 30 | 90 days) scopes the whole page;
 * because it's URL-bound, a chosen range is shareable and bookmarkable, and
 * the computed properties recompute whenever it changes.
 */
new #[Title('Reports')] class extends Component {
    /**
     * The look-back window in days. URL-bound so the range survives a refresh
     * and is shareable. Only 7/30/90 are offered in the UI, but the value is
     * clamped in `to`/`from` math regardless.
     */
    #[Url]
    public int $range = 30;

    /**
     * Stat-card numbers — point-in-time, not range-scoped.
     *
     * @return array{open: int, unassigned: int, urgent: int, breached: int, overdue: int}
     */
    #[Computed]
    public function snapshot(): array
    {
        return (new QueueSnapshot($this->team()))->counts();
    }

    /**
     * The created-vs-resolved series for the volume chart, as a list of rows
     * the Flux chart binds to: [['date' => '2026-05-12', 'created' => 3,
     * 'resolved' => 1], ...]. RequestVolume returns a date-keyed map; the
     * chart wants a positional list with the date inside each row, so we
     * flatten here.
     *
     * @return list<array{date: string, created: int, resolved: int}>
     */
    #[Computed]
    public function volume(): array
    {
        $perDay = (new RequestVolume($this->team(), $this->from(), $this->to()))->perDay();

        return collect($perDay)
            ->map(fn (array $counts, string $date) => ['date' => $date, ...$counts])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function categories(): Collection
    {
        return (new CategoryPerformance($this->team(), $this->from(), $this->to()))->rows();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function agents(): Collection
    {
        return (new AgentPerformance($this->team(), $this->from(), $this->to()))->rows();
    }

    /**
     * The viewer's current team — every query is scoped to it.
     */
    private function team(): \App\Models\Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * The half-open window's exclusive end: the start of tomorrow, so today's
     * activity is fully included.
     */
    private function to(): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfDay()->addDay();
    }

    /**
     * The inclusive window start: `range` whole days back from `to`.
     */
    private function from(): CarbonImmutable
    {
        return $this->to()->subDays($this->range);
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="xl">{{ __('Reports') }}</flux:heading>

        <flux:select wire:model.live="range" class="max-w-48" data-test="range-select">
            <flux:select.option value="7">{{ __('Last 7 days') }}</flux:select.option>
            <flux:select.option value="30">{{ __('Last 30 days') }}</flux:select.option>
            <flux:select.option value="90">{{ __('Last 90 days') }}</flux:select.option>
        </flux:select>
    </div>

    {{-- Stat cards: the queue's current shape. SLA-breached and overdue route
         through Request::scopeSlaBreached / scopeSlaOverdue — the same
         definition the category table and Phase 6 automation use. --}}
    <div class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-5" data-test="stat-cards">
        <flux:card class="space-y-1" data-test="stat-open">
            <flux:text>{{ __('Open') }}</flux:text>
            <flux:heading size="xl" class="tabular-nums">{{ $this->snapshot['open'] }}</flux:heading>
        </flux:card>

        <flux:card class="space-y-1" data-test="stat-unassigned">
            <flux:text>{{ __('Unassigned') }}</flux:text>
            <flux:heading size="xl" class="tabular-nums">{{ $this->snapshot['unassigned'] }}</flux:heading>
        </flux:card>

        <flux:card class="space-y-1" data-test="stat-urgent">
            <flux:text>{{ __('Urgent') }}</flux:text>
            <flux:heading size="xl" class="tabular-nums">{{ $this->snapshot['urgent'] }}</flux:heading>
        </flux:card>

        <flux:card class="space-y-1" data-test="stat-breached">
            <flux:text>{{ __('SLA breached') }}</flux:text>
            <flux:heading size="xl" class="tabular-nums {{ $this->snapshot['breached'] > 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                {{ $this->snapshot['breached'] }}
            </flux:heading>
        </flux:card>

        <flux:card class="space-y-1" data-test="stat-overdue">
            <flux:text>{{ __('Overdue') }}</flux:text>
            <flux:heading size="xl" class="tabular-nums {{ $this->snapshot['overdue'] > 0 ? 'text-amber-600 dark:text-amber-400' : '' }}">
                {{ $this->snapshot['overdue'] }}
            </flux:heading>
        </flux:card>
    </div>

    {{-- Requests over time: created vs resolved per day, fed the zero-filled
         series straight from RequestVolume (no query logic in the view). --}}
    <flux:card class="mt-6" data-test="volume-chart">
        <flux:heading size="lg">{{ __('Requests over time') }}</flux:heading>

        <flux:chart wire:model="volume" class="mt-4 aspect-[3/1]">
            <flux:chart.viewport>
                <flux:chart.svg>
                    <flux:chart.line field="created" class="text-blue-500 dark:text-blue-400" curve="none" />
                    <flux:chart.area field="created" class="text-blue-200/40 dark:text-blue-400/20" curve="none" />
                    <flux:chart.line field="resolved" class="text-green-500 dark:text-green-400" curve="none" />

                    <flux:chart.axis axis="x" field="date">
                        <flux:chart.axis.tick />
                        <flux:chart.axis.line />
                    </flux:chart.axis>

                    <flux:chart.axis axis="y" :format="['maximumFractionDigits' => 0]">
                        <flux:chart.axis.grid />
                        <flux:chart.axis.tick />
                    </flux:chart.axis>

                    <flux:chart.cursor />
                </flux:chart.svg>
            </flux:chart.viewport>

            <div class="flex justify-center gap-4 pt-4">
                <flux:chart.legend label="{{ __('Created') }}">
                    <flux:chart.legend.indicator class="bg-blue-400" />
                </flux:chart.legend>
                <flux:chart.legend label="{{ __('Resolved') }}">
                    <flux:chart.legend.indicator class="bg-green-400" />
                </flux:chart.legend>
            </div>
        </flux:chart>
    </flux:card>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        {{-- Requests by category — the SLA report. A category row goes red
             when any of its in-range requests breached. The SLA column shows
             target vs the actual average first response. --}}
        <flux:card data-test="category-table">
            <flux:heading size="lg">{{ __('Requests by category') }}</flux:heading>

            <flux:table class="mt-4">
                <flux:table.columns>
                    <flux:table.column>{{ __('Category') }}</flux:table.column>
                    <flux:table.column>{{ __('Requests') }}</flux:table.column>
                    <flux:table.column>{{ __('Avg first response') }}</flux:table.column>
                    <flux:table.column>{{ __('SLA target') }}</flux:table.column>
                    <flux:table.column>{{ __('Breached') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->categories as $category)
                        <flux:table.row :key="$category->id" data-test="category-row">
                            <flux:table.cell variant="strong">{{ $category->name }}</flux:table.cell>
                            <flux:table.cell class="tabular-nums">{{ $category->count }}</flux:table.cell>
                            <flux:table.cell class="tabular-nums">
                                {{ $category->avgFirstResponseMinutes === null ? '—' : __(':minutes min', ['minutes' => $category->avgFirstResponseMinutes]) }}
                            </flux:table.cell>
                            <flux:table.cell class="tabular-nums">
                                {{ $category->slaTargetMinutes === null ? '—' : __(':minutes min', ['minutes' => $category->slaTargetMinutes]) }}
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($category->breached > 0)
                                    <flux:badge color="red" size="sm" inset="top bottom" data-test="breach-badge">{{ $category->breached }}</flux:badge>
                                @else
                                    <span class="tabular-nums text-zinc-500 dark:text-zinc-400">0</span>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5" class="text-center text-zinc-500 dark:text-zinc-400">
                                {{ __('No categories yet.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>

        {{-- Agent performance — one row per current staff member, including
             idle agents (zero rows, not absent). --}}
        <flux:card data-test="agent-table">
            <flux:heading size="lg">{{ __('Agent performance') }}</flux:heading>

            <flux:table class="mt-4">
                <flux:table.columns>
                    <flux:table.column>{{ __('Agent') }}</flux:table.column>
                    <flux:table.column>{{ __('Open assigned') }}</flux:table.column>
                    <flux:table.column>{{ __('Resolved') }}</flux:table.column>
                    <flux:table.column>{{ __('Avg first response') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->agents as $agent)
                        <flux:table.row :key="$agent->id" data-test="agent-row">
                            <flux:table.cell variant="strong">{{ $agent->name }}</flux:table.cell>
                            <flux:table.cell class="tabular-nums">{{ $agent->openAssigned }}</flux:table.cell>
                            <flux:table.cell class="tabular-nums">{{ $agent->resolvedInRange }}</flux:table.cell>
                            <flux:table.cell class="tabular-nums">
                                {{ $agent->avgFirstResponseMinutes === null ? '—' : __(':minutes min', ['minutes' => $agent->avgFirstResponseMinutes]) }}
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="4" class="text-center text-zinc-500 dark:text-zinc-400">
                                {{ __('No staff yet.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>
    </div>
</section>
