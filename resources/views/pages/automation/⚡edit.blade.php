<?php

use App\Enums\ConditionField;
use App\Enums\ConditionOperator;
use App\Enums\RequestStatus;
use App\Enums\RuleAction;
use App\Enums\RuleLayer;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/*
 * The rule builder — create or edit one automation rule.
 *
 * One component, two routes: `automation/create` (no model) and
 * `automation/{rule}` (route-model-bound). The layer is fixed once a rule
 * exists — its conditions/actions were designed for that layer's subject, so
 * switching layers mid-edit would orphan them. Conditions and actions are
 * array-prop repeaters: add/remove rows in the UI, each row a {field,operator,
 * value} or {action,value} map that maps straight onto the JSON columns.
 *
 * Validation rejects unknown enum values server-side (Rule::enum), so a tampered
 * <select> can't store a field/operator/action the engine doesn't understand —
 * the value-object hydration would otherwise throw at evaluation time.
 */
new class extends Component {
    public ?AutomationRule $ruleModel = null;

    public string $name = '';

    public string $layer = 'trigger';

    public ?string $event = 'request_created';

    public bool $isActive = true;

    /** @var list<array{field: string, operator: string, value: mixed}> */
    public array $conditions = [];

    /** @var list<array{action: string, value: mixed}> */
    public array $actions = [];

    public function mount(?AutomationRule $rule = null): void
    {
        if ($rule !== null && $rule->exists) {
            abort_unless($rule->team_id === Auth::user()->current_team_id, 404);

            $this->ruleModel = $rule;
            $this->name = $rule->name;
            $this->layer = $rule->layer->value;
            $this->event = $rule->event;
            $this->isActive = $rule->is_active;
            $this->conditions = $rule->conditions;
            $this->actions = $rule->actions;
        }

        // A brand-new rule starts with one empty condition + action row so the
        // builder isn't a blank page.
        if ($this->conditions === []) {
            $this->addCondition();
        }

        if ($this->actions === []) {
            $this->addAction();
        }
    }

    public function addCondition(): void
    {
        $this->conditions[] = ['field' => ConditionField::Subject->value, 'operator' => ConditionOperator::Contains->value, 'value' => ''];
    }

    public function removeCondition(int $index): void
    {
        unset($this->conditions[$index]);
        $this->conditions = array_values($this->conditions);
    }

    public function addAction(): void
    {
        $this->actions[] = ['action' => RuleAction::SetUrgent->value, 'value' => true];
    }

    public function removeAction(int $index): void
    {
        unset($this->actions[$index]);
        $this->actions = array_values($this->actions);
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            // Layer is only settable on create; on edit the disabled field
            // still posts its current value, validated against the enum.
            'layer' => ['required', Rule::enum(RuleLayer::class)],
            'event' => ['nullable', Rule::in(['request_created', 'request_status_changed', 'note_added'])],
            'isActive' => ['boolean'],
            'conditions' => ['array'],
            'conditions.*.field' => ['required', Rule::enum(ConditionField::class)],
            'conditions.*.operator' => ['required', Rule::enum(ConditionOperator::class)],
            'actions' => ['array', 'min:1'],
            'actions.*.action' => ['required', Rule::enum(RuleAction::class)],
        ]);

        $layer = RuleLayer::from($validated['layer']);

        // Trigger rules need an event; the other layers must not carry one.
        $event = $layer === RuleLayer::Trigger ? $validated['event'] : null;

        $attributes = [
            'team_id' => Auth::user()->current_team_id,
            'layer' => $layer,
            'event' => $event,
            'name' => $validated['name'],
            'is_active' => $this->isActive,
            'conditions' => array_values($this->conditions),
            'actions' => array_values($this->actions),
        ];

        if ($this->ruleModel === null) {
            // Append to the end of this layer so a new rule never silently
            // jumps ahead of existing mail rules (where order decides winners).
            $attributes['position'] = (int) AutomationRule::query()
                ->where('team_id', $attributes['team_id'])
                ->where('layer', $layer->value)
                ->max('position') + 1;

            AutomationRule::create($attributes);
        } else {
            // Layer is immutable after create — keep the stored one regardless
            // of what the (disabled) field posted.
            unset($attributes['layer']);
            $this->ruleModel->update($attributes);
        }

        Flux::toast(variant: 'success', text: __('Rule saved.'));

        $this->redirectRoute('automation.index', ['current_team' => Auth::user()->currentTeam->slug], navigate: true);
    }

    /**
     * The condition fields offered for the current layer (email-shaped for
     * mail, request-shaped for trigger/scheduled) — the named mitigation
     * against "age_hours on a mail rule silently never matches."
     *
     * @return list<ConditionField>
     */
    #[Computed]
    public function fieldOptions(): array
    {
        return RuleLayer::from($this->layer)->conditionFields();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Category>
     */
    #[Computed]
    public function categories(): \Illuminate\Support\Collection
    {
        return Category::query()->where('team_id', Auth::user()->current_team_id)->orderBy('name')->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    #[Computed]
    public function teamMembers(): \Illuminate\Support\Collection
    {
        return Auth::user()->currentTeam->members()->orderBy('name')->get();
    }

    /**
     * @return list<ConditionOperator>
     */
    public function operatorOptions(): array
    {
        return ConditionOperator::cases();
    }

    /**
     * @return list<RuleAction>
     */
    public function actionOptions(): array
    {
        return RuleAction::cases();
    }

    /**
     * @return list<RequestStatus>
     */
    public function statusOptions(): array
    {
        return RequestStatus::cases();
    }

    public function render()
    {
        return $this->view()->title($this->ruleModel === null ? __('New rule') : __('Edit rule'));
    }
}; ?>

<section class="mx-auto w-full max-w-3xl">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">{{ $ruleModel === null ? __('New rule') : __('Edit rule') }}</flux:heading>
        <flux:button variant="ghost" :href="route('automation.index', ['current_team' => auth()->user()->currentTeam->slug])" wire:navigate icon="arrow-left">
            {{ __('Back') }}
        </flux:button>
    </div>

    <form wire:submit="save" class="mt-6 space-y-8" data-test="automation-rule-form">
        <div class="space-y-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:input wire:model="name" :label="__('Rule name')" required data-test="rule-name" />

            <flux:select wire:model.live="layer" :label="__('Layer')" :disabled="$ruleModel !== null" data-test="rule-layer">
                @foreach (\App\Enums\RuleLayer::cases() as $layerOption)
                    <flux:select.option value="{{ $layerOption->value }}">{{ $layerOption->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($layer === 'trigger')
                <flux:select wire:model="event" :label="__('Fires on event')" data-test="rule-event">
                    <flux:select.option value="request_created">{{ __('Request created') }}</flux:select.option>
                    <flux:select.option value="request_status_changed">{{ __('Request status changed') }}</flux:select.option>
                    <flux:select.option value="note_added">{{ __('Note added') }}</flux:select.option>
                </flux:select>
            @endif

            <flux:switch wire:model="isActive" :label="__('Active')" data-test="rule-active" />
        </div>

        {{-- Conditions: AND-ed rows. Empty = always match. --}}
        <div class="space-y-3">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('Conditions') }}</flux:heading>
                <flux:button type="button" variant="ghost" size="sm" icon="plus" wire:click="addCondition" data-test="add-condition">
                    {{ __('Add condition') }}
                </flux:button>
            </div>
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('All conditions must hold (AND). Leave empty to match every subject.') }}
            </flux:text>

            @foreach ($conditions as $index => $condition)
                <div wire:key="condition-{{ $index }}" class="flex flex-wrap items-end gap-2 rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900" data-test="condition-row">
                    <flux:select wire:model="conditions.{{ $index }}.field" class="min-w-40" :label="__('Field')" data-test="condition-field">
                        @foreach ($this->fieldOptions as $field)
                            <flux:select.option value="{{ $field->value }}">{{ $field->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="conditions.{{ $index }}.operator" class="min-w-36" :label="__('Operator')" data-test="condition-operator">
                        @foreach ($this->operatorOptions() as $operator)
                            <flux:select.option value="{{ $operator->value }}">{{ $operator->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:input wire:model="conditions.{{ $index }}.value" class="min-w-40 flex-1" :label="__('Value')" data-test="condition-value" />

                    <flux:button type="button" variant="ghost" size="sm" icon="x-mark" wire:click="removeCondition({{ $index }})" data-test="remove-condition" :aria-label="__('Remove condition')" />
                </div>
            @endforeach
        </div>

        {{-- Actions: applied in order when the rule matches. --}}
        <div class="space-y-3">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('Actions') }}</flux:heading>
                <flux:button type="button" variant="ghost" size="sm" icon="plus" wire:click="addAction" data-test="add-action">
                    {{ __('Add action') }}
                </flux:button>
            </div>

            @error('actions')
                <flux:text class="text-sm text-red-600 dark:text-red-400" data-test="actions-error">{{ $message }}</flux:text>
            @enderror

            @foreach ($actions as $index => $action)
                <div wire:key="action-{{ $index }}" class="flex flex-wrap items-end gap-2 rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900" data-test="action-row">
                    <flux:select wire:model.live="actions.{{ $index }}.action" class="min-w-44" :label="__('Action')" data-test="action-type">
                        @foreach ($this->actionOptions() as $actionOption)
                            <flux:select.option value="{{ $actionOption->value }}">{{ $actionOption->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    {{-- The value input swaps by action type: a category select,
                         a user select, a status select, or free text. --}}
                    @switch($action['action'])
                        @case('set_category')
                            <flux:select wire:model="actions.{{ $index }}.value" class="min-w-44" :label="__('Category')" data-test="action-value">
                                @foreach ($this->categories as $category)
                                    <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            @break

                        @case('assign_to')
                        @case('notify_user')
                            <flux:select wire:model="actions.{{ $index }}.value" class="min-w-44" :label="__('User')" data-test="action-value">
                                @foreach ($this->teamMembers as $member)
                                    <flux:select.option value="{{ $member->id }}">{{ $member->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            @break

                        @case('change_status')
                            <flux:select wire:model="actions.{{ $index }}.value" class="min-w-44" :label="__('Status')" data-test="action-value">
                                @foreach ($this->statusOptions() as $status)
                                    <flux:select.option value="{{ $status->value }}">{{ $status->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            @break

                        @case('set_urgent')
                            <flux:select wire:model="actions.{{ $index }}.value" class="min-w-32" :label="__('Value')" data-test="action-value">
                                <flux:select.option value="1">{{ __('Urgent') }}</flux:select.option>
                                <flux:select.option value="0">{{ __('Not urgent') }}</flux:select.option>
                            </flux:select>
                            @break

                        @default
                            <flux:input wire:model="actions.{{ $index }}.value" class="min-w-44 flex-1" :label="__('Note text')" data-test="action-value" />
                    @endswitch

                    <flux:button type="button" variant="ghost" size="sm" icon="x-mark" wire:click="removeAction({{ $index }})" data-test="remove-action" :aria-label="__('Remove action')" />
                </div>
            @endforeach
        </div>

        <div class="flex justify-end gap-2">
            <flux:button variant="ghost" :href="route('automation.index', ['current_team' => auth()->user()->currentTeam->slug])" wire:navigate>{{ __('Cancel') }}</flux:button>
            <flux:button variant="primary" type="submit" data-test="rule-save">{{ __('Save rule') }}</flux:button>
        </div>
    </form>
</section>
