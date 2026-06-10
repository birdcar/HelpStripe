<?php

use App\Models\Filter;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

/*
 * Saves the queue's current criteria as a named Filter.
 *
 * The criteria arrive via a Livewire event rather than a mount() prop:
 * the parent queue component's filters change constantly after mount,
 * and events deliver the *current* values at the moment the modal opens.
 * (Pattern: components/⚡create-team-modal.blade.php for the modal shell.)
 */
new class extends Component {
    public string $name = '';

    public bool $isShared = false;

    /** @var array<string, mixed> */
    public array $criteria = [];

    #[On('save-filter-modal:open')]
    public function open(array $criteria): void
    {
        $this->criteria = $criteria;

        Flux::modal('save-filter')->show();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'isShared' => ['boolean'],
        ]);

        Filter::create([
            'team_id' => Auth::user()->current_team_id,
            'user_id' => Auth::id(),
            'name' => $validated['name'],
            'is_shared' => $validated['isShared'],
            'criteria' => $this->criteria,
        ]);

        Flux::modal('save-filter')->close();

        $this->reset('name', 'isShared', 'criteria');

        Flux::toast(variant: 'success', text: __('Filter saved.'));
    }
}; ?>

<flux:modal name="save-filter" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
    <form wire:submit="save" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Save filter') }}</flux:heading>
            <flux:subheading>{{ __('Save the current queue view so you can reapply it later.') }}</flux:subheading>
        </div>

        <div class="space-y-4">
            <flux:input wire:model="name" :label="__('Filter name')" type="text" required autofocus data-test="save-filter-name" />

            <flux:switch wire:model="isShared" :label="__('Share with the whole team')" data-test="save-filter-shared" />
        </div>

        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>

            <flux:button variant="primary" type="submit" data-test="save-filter-submit">
                {{ __('Save filter') }}
            </flux:button>
        </div>
    </form>
</flux:modal>
