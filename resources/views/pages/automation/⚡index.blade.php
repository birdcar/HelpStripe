<?php

use App\Enums\RuleLayer;
use App\Models\AutomationRule;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Automation rules index — all three layers in one list, grouped by layer.
 *
 * The route sits behind `can:manage automation`, so anyone here holds the
 * permission; the per-action team guard protects against passing another
 * team's rule id to a Livewire action. The edit page is where rules are
 * built; this page lists them, toggles active, reorders, and deletes.
 */
new #[Title('Automation')] class extends Component {
    public function toggleActive(AutomationRule $rule): void
    {
        $this->ensureCurrentTeamOwns($rule);

        $rule->update(['is_active' => ! $rule->is_active]);

        Flux::toast(
            variant: 'success',
            text: $rule->is_active ? __('Rule enabled.') : __('Rule disabled.'),
        );
    }

    public function deleteRule(AutomationRule $rule): void
    {
        $this->ensureCurrentTeamOwns($rule);

        $rule->delete();

        Flux::toast(variant: 'success', text: __('Rule deleted.'));
    }

    /**
     * Reorder within the rule's own layer by swapping positions with the
     * neighbor — the same two-UPDATE approach the KB manager uses. Ordering
     * matters most for mail rules (later wins on the same field), so the swap
     * stays scoped to the layer.
     */
    public function move(AutomationRule $rule, string $direction): void
    {
        $this->ensureCurrentTeamOwns($rule);

        $neighbor = AutomationRule::query()
            ->where('team_id', $rule->team_id)
            ->where('layer', $rule->layer->value)
            ->when(
                $direction === 'up',
                fn ($query) => $query->where('position', '<', $rule->position)->orderByDesc('position'),
                fn ($query) => $query->where('position', '>', $rule->position)->orderBy('position'),
            )
            ->first();

        if ($neighbor === null) {
            return; // Already at the edge of its layer.
        }

        DB::transaction(function () use ($rule, $neighbor) {
            [$rulePosition, $neighborPosition] = [$neighbor->position, $rule->position];
            $rule->update(['position' => $rulePosition]);
            $neighbor->update(['position' => $neighborPosition]);
        });
    }

    /**
     * All the team's rules, grouped by layer (mail/trigger/scheduled) and
     * ordered within each group, so the view can render one section per layer.
     *
     * @return Collection<int, AutomationRule>
     */
    #[Computed]
    public function rules(): Collection
    {
        return AutomationRule::query()
            ->where('team_id', Auth::user()->current_team_id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * The layers in display order — every layer gets a section even when empty.
     *
     * @return array<int, RuleLayer>
     */
    public function layers(): array
    {
        return RuleLayer::cases();
    }

    private function ensureCurrentTeamOwns(AutomationRule $rule): void
    {
        abort_unless($rule->team_id === Auth::user()->current_team_id, 404);
    }
}; ?>

<section class="mx-auto w-full max-w-4xl">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Automation') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                {{ __('Mail Rules run on inbound email, Triggers run on events, and Automation Rules run on a schedule.') }}
            </flux:text>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('automation.create', ['current_team' => auth()->user()->currentTeam->slug])" wire:navigate data-test="automation-new-rule-button">
            {{ __('New rule') }}
        </flux:button>
    </div>

    <div class="mt-6 space-y-8">
        @foreach ($this->layers() as $layer)
            @php($layerRules = $this->rules->where('layer', $layer))
            <div data-test="automation-layer-{{ $layer->value }}">
                <flux:heading size="lg">{{ $layer->label() }}s</flux:heading>

                <div class="mt-3 space-y-3">
                    @forelse ($layerRules as $rule)
                        <div
                            wire:key="rule-{{ $rule->id }}"
                            class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900"
                            data-test="automation-rule-row"
                        >
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <flux:link :href="route('automation.edit', ['current_team' => auth()->user()->currentTeam->slug, 'rule' => $rule])" wire:navigate class="font-medium" data-test="automation-rule-link">
                                        {{ $rule->name }}
                                    </flux:link>

                                    @if ($rule->is_active)
                                        <flux:badge color="green" size="sm">{{ __('Active') }}</flux:badge>
                                    @else
                                        <flux:badge color="zinc" size="sm">{{ __('Inactive') }}</flux:badge>
                                    @endif
                                </div>

                                <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                    @if ($rule->event)
                                        {{ __('on :event', ['event' => str_replace('_', ' ', $rule->event)]) }} ·
                                    @endif
                                    {{ trans_choice(':count condition|:count conditions', count($rule->conditions), ['count' => count($rule->conditions)]) }}
                                    ·
                                    {{ trans_choice(':count action|:count actions', count($rule->actions), ['count' => count($rule->actions)]) }}
                                    @if ($layer === \App\Enums\RuleLayer::Scheduled && $rule->last_run_at)
                                        · {{ __('last run :time', ['time' => $rule->last_run_at->diffForHumans()]) }}
                                    @endif
                                </flux:text>
                            </div>

                            <div class="flex shrink-0 items-center gap-1">
                                <flux:button variant="ghost" size="sm" icon="chevron-up" wire:click="move({{ $rule->id }}, 'up')" data-test="automation-rule-up" :aria-label="__('Move up')" />
                                <flux:button variant="ghost" size="sm" icon="chevron-down" wire:click="move({{ $rule->id }}, 'down')" data-test="automation-rule-down" :aria-label="__('Move down')" />
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    :icon="$rule->is_active ? 'pause' : 'play'"
                                    wire:click="toggleActive({{ $rule->id }})"
                                    data-test="automation-rule-toggle"
                                    :aria-label="$rule->is_active ? __('Disable') : __('Enable')"
                                />
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    wire:click="deleteRule({{ $rule->id }})"
                                    wire:confirm="{{ __('Delete this rule?') }}"
                                    data-test="automation-rule-delete"
                                    :aria-label="__('Delete rule')"
                                />
                            </div>
                        </div>
                    @empty
                        <flux:text class="py-4 text-sm text-zinc-500 dark:text-zinc-400" data-test="automation-layer-empty">
                            {{ __('No :layer rules yet.', ['layer' => strtolower($layer->label())]) }}
                        </flux:text>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>
</section>
