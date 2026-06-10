<?php

use App\Actions\Requests\AddNote;
use App\Actions\Requests\ChangeStatus;
use App\Enums\RequestSource;
use App\Enums\RequestStatus;
use App\Models\Note;
use App\Models\Request;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/*
 * The customer-facing view of a single request. Two routes mount this one
 * component:
 *
 *  - portal.status (signed): the link in the confirmation email. The
 *    `signed` route middleware verified the HMAC before mount() runs, so
 *    arriving here at all proves the URL is genuine. We persist that grant
 *    in the session so a later reply (an unsigned Livewire request) is still
 *    authorized.
 *  - portal.status.show (unsigned): reached after a manual email+key lookup,
 *    which set the same session flag. mount() rejects anyone without it.
 *
 * The timeline shows PUBLIC notes only. Private staff notes must never reach
 * a customer — the query filters is_private = false and a dedicated test
 * asserts a private note's body is absent from the rendered HTML.
 */
new #[Layout('layouts::portal')] class extends Component {
    public Request $helpdeskRequest;

    #[Validate('required|string|max:5000')]
    public string $replyBody = '';

    public function mount(Request $request): void
    {
        // The signed route's middleware already authorized the request; a
        // signed arrival also (re)grants session access so subsequent
        // unsigned Livewire calls (the reply) keep working. The unsigned
        // route has no middleware, so it relies solely on the session flag
        // a prior signed visit or manual lookup set.
        if (request()->hasValidSignature()) {
            Session::put($this->verifiedKey($request->id), true);
        }

        abort_unless(Session::get($this->verifiedKey($request->id)) === true, 403);

        $this->helpdeskRequest = $request;
    }

    public function render(): mixed
    {
        return $this->view()->title('Request #'.$this->helpdeskRequest->id);
    }

    /**
     * The public timeline only — private staff notes are filtered out at
     * the query, not the view, so a private note's body never reaches the
     * browser even in the rendered HTML.
     *
     * @return Collection<int, Note>
     */
    #[Computed]
    public function publicNotes(): Collection
    {
        return $this->helpdeskRequest->notes()
            ->where('is_private', false)
            ->with('user', 'customer')
            ->oldest()
            ->oldest('id')
            ->get();
    }

    /**
     * Add the customer's reply to the timeline.
     *
     * Routes through the same AddNote action staff and the email pipeline
     * use, authored by the Customer with RequestSource::Portal. A reply on a
     * Resolved/Closed request reopens it (it isn't resolved for the
     * customer) — identical to a customer's email reply, and ChangeStatus
     * owns the resolved_at bookkeeping.
     */
    public function reply(AddNote $addNote, ChangeStatus $changeStatus): void
    {
        abort_unless(Session::get($this->verifiedKey($this->helpdeskRequest->id)) === true, 403);

        $validated = $this->validate();

        $addNote->handle(
            $this->helpdeskRequest,
            $this->helpdeskRequest->customer,
            $validated['replyBody'],
            isPrivate: false,
            source: RequestSource::Portal,
        );

        if (in_array($this->helpdeskRequest->status, [RequestStatus::Resolved, RequestStatus::Closed], true)) {
            $changeStatus->handle($this->helpdeskRequest, RequestStatus::Active);
            $this->helpdeskRequest->refresh();
        }

        $this->reset('replyBody');
        unset($this->publicNotes);
    }

    /**
     * The session key that marks this request as verified for this visitor.
     */
    private function verifiedKey(int $requestId): string
    {
        return "portal.verified.{$requestId}";
    }
}; ?>

<div>
    <flux:text class="text-sm">
        <flux:link :href="route('portal.home')" wire:navigate>{{ __('Support') }}</flux:link>
    </flux:text>

    <div class="mt-1 flex items-start justify-between gap-4">
        <flux:heading size="xl">{{ $helpdeskRequest->subject }}</flux:heading>
        <flux:badge :color="$helpdeskRequest->status->color()" data-test="portal-status-badge">
            {{ $helpdeskRequest->status->label() }}
        </flux:badge>
    </div>

    <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
        {{ __('Request :number', ['number' => '#'.$helpdeskRequest->id]) }}
    </flux:text>

    <div class="mt-8 space-y-3" data-test="portal-timeline">
        @forelse ($this->publicNotes as $note)
            @if ($note->isFromCustomer())
                <div
                    wire:key="note-{{ $note->id }}"
                    class="ms-8 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900"
                    data-test="portal-customer-note"
                >
                    <div class="flex items-center gap-2">
                        <flux:avatar size="xs" :name="$note->customer->name" />
                        <span class="font-medium">{{ $note->customer->name }}</span>
                        <flux:text class="text-xs text-zinc-500">{{ $note->created_at->diffForHumans() }}</flux:text>
                    </div>
                    <flux:text class="mt-2 whitespace-pre-line">{{ $note->body }}</flux:text>
                </div>
            @else
                <div
                    wire:key="note-{{ $note->id }}"
                    class="me-8 rounded-lg border border-sky-300 bg-sky-50 p-4 dark:border-sky-500/40 dark:bg-sky-950/40"
                    data-test="portal-staff-note"
                >
                    <div class="flex items-center gap-2">
                        <flux:avatar size="xs" :name="$note->user?->name ?? __('Support')" />
                        <span class="font-medium">{{ $note->user?->name ?? __('Support') }}</span>
                        <flux:text class="text-xs text-zinc-500">{{ $note->created_at->diffForHumans() }}</flux:text>
                    </div>
                    <flux:text class="mt-2 whitespace-pre-line">{{ $note->body }}</flux:text>
                </div>
            @endif
        @empty
            <flux:text class="py-8 text-center text-zinc-500 dark:text-zinc-400" data-test="portal-timeline-empty">
                {{ __('No replies yet — we will be in touch soon.') }}
            </flux:text>
        @endforelse
    </div>

    <form wire:submit="reply" class="mt-8 flex flex-col gap-3 border-t border-zinc-200 pt-6 dark:border-zinc-700">
        <flux:heading size="lg">{{ __('Add a reply') }}</flux:heading>

        <flux:textarea
            wire:model="replyBody"
            :placeholder="__('Add more detail or respond to our team…')"
            rows="4"
            data-test="portal-reply-body"
        />

        <div>
            <flux:button type="submit" variant="primary" data-test="portal-reply-button">
                {{ __('Send reply') }}
            </flux:button>
        </div>
    </form>
</div>
