<?php

use App\Actions\Requests\AddNote;
use App\Actions\Requests\AssignRequest;
use App\Actions\Requests\ChangeStatus;
use App\Enums\RequestStatus;
use App\Models\Category;
use App\Models\Note;
use App\Models\Request;
use App\Models\Response;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

/*
 * Request detail — timeline, reply box, properties panel, history tab.
 *
 * Property changes are optimistic: each select/toggle saves immediately
 * through its action (no "edit mode", no save button) and confirms with
 * a toast. The activity log records every change, so the History tab is
 * the audit trail HelpSpot promised: single assignment, full history.
 */
new class extends Component {
    public Request $helpdeskRequest;

    /**
     * Other staff currently looking at this request, keyed by user id.
     * Maintained entirely client-side from the presence channel — no DB
     * writes. Presence is ephemeral by design: it lives for the duration
     * of the websocket connection and disappears when the tab closes.
     *
     * @var array<int, array{id: int, name: string}>
     */
    public array $viewers = [];

    public string $replyMode = 'public';

    public string $replyBody = '';

    public string $selectedResponse = '';

    public string $status = '';

    public string $assignee = '';

    public string $category = '';

    public bool $urgent = false;

    public string $tags = '';

    /**
     * `{request}` route-model-binds to App\Models\Request — the ticket,
     * not Illuminate\Http\Request. The policy check is what turns a
     * cross-team request id into a 403; EnsureTeamMembership only proves
     * the *URL's* team is yours, not that this request belongs to it.
     */
    public function mount(Request $request): void
    {
        Gate::authorize('view', $request);

        $this->helpdeskRequest = $request;

        $this->status = $request->status->value;
        $this->assignee = (string) ($request->assigned_to ?? '');
        $this->category = (string) ($request->category_id ?? '');
        $this->urgent = $request->is_urgent;
        $this->tags = $request->tags->pluck('name')->implode(', ');
    }

    /**
     * Echo listeners for THIS request's presence channel.
     *
     * The channel name embeds the request id, so the listeners can't be
     * declared with the static `#[On('echo-presence:…')]` attribute (which
     * can't interpolate a bound model id) — getListeners() builds them at
     * runtime instead. Four signals on one channel:
     *
     *  - `here`    — fired once on join with the full current roster.
     *  - `joining` — a viewer arrived.
     *  - `leaving` — a viewer left.
     *  - `NoteAdded` — someone replied; refresh the timeline. (This is the
     *    NoteAdded broadcast event; toOthers() on dispatch means the author's
     *    own page never receives its own note here.)
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        $channel = 'echo-presence:request.'.$this->helpdeskRequest->id;

        return [
            "{$channel},here" => 'syncViewers',
            "{$channel},joining" => 'viewerJoined',
            "{$channel},leaving" => 'viewerLeft',
            "{$channel},NoteAdded" => 'refreshTimeline',
        ];
    }

    /**
     * The initial roster (the `here` event) — an array of member payloads
     * as returned by the channel authorization closure. Replace the local
     * map wholesale, excluding ourselves (you never collide with yourself).
     *
     * @param  array<int, array{id: int, name: string}>  $members
     */
    public function syncViewers(array $members): void
    {
        $this->viewers = [];

        foreach ($members as $member) {
            $this->addViewer($member);
        }
    }

    /**
     * @param  array{id: int, name: string}  $member
     */
    public function viewerJoined(array $member): void
    {
        $this->addViewer($member);
    }

    /**
     * @param  array{id: int, name: string}  $member
     */
    public function viewerLeft(array $member): void
    {
        unset($this->viewers[$member['id']]);
    }

    /**
     * A remote NoteAdded broadcast: forget the cached timeline so the next
     * render re-queries it through the authorized computed property. The
     * websocket payload carries only the note id (broadcastWith) — the body
     * is never trusted from the wire, which is what keeps a private note
     * from leaking to a browser that shouldn't render it.
     */
    public function refreshTimeline(): void
    {
        unset($this->notes);
    }

    /**
     * Dedupe by user id and skip the current viewer. Two tabs from the same
     * person count once — the banner is about *other* people on the ticket.
     *
     * @param  array{id: int, name: string}  $member
     */
    private function addViewer(array $member): void
    {
        if ((int) $member['id'] === Auth::id()) {
            return;
        }

        $this->viewers[$member['id']] = ['id' => $member['id'], 'name' => $member['name']];
    }

    public function addNote(AddNote $addNote): void
    {
        Gate::authorize('update', $this->helpdeskRequest);

        $validated = $this->validate([
            'replyBody' => ['required', 'string'],
        ]);

        $addNote->handle(
            $this->helpdeskRequest,
            Auth::user(),
            $validated['replyBody'],
            isPrivate: $this->replyMode === 'private',
        );

        $this->reset('replyBody', 'selectedResponse');

        unset($this->notes);

        Flux::toast(variant: 'success', text: $this->replyMode === 'private'
            ? __('Private note added.')
            : __('Reply sent.'));
    }

    /**
     * The Response picker: choosing a canned reply appends its body to
     * the draft (appends, not replaces — agents often pick a canned
     * opener and keep typing).
     */
    public function updatedSelectedResponse(string $value): void
    {
        if ($value === '') {
            return;
        }

        $response = Response::query()
            ->where('team_id', Auth::user()->current_team_id)
            ->find((int) $value);

        if ($response === null) {
            return;
        }

        $this->replyBody = trim($this->replyBody) === ''
            ? $response->body
            : $this->replyBody."\n\n".$response->body;
    }

    public function updatedStatus(string $value): void
    {
        Gate::authorize('update', $this->helpdeskRequest);

        $newStatus = RequestStatus::tryFrom($value);

        if ($newStatus === null) {
            $this->status = $this->helpdeskRequest->status->value;

            return;
        }

        // updated* hooks don't get method injection the way actions do,
        // so the action class is resolved from the container explicitly.
        app(ChangeStatus::class)->handle($this->helpdeskRequest, $newStatus);

        unset($this->activities);

        Flux::toast(variant: 'success', text: __('Status updated.'));
    }

    public function updatedAssignee(string $value): void
    {
        Gate::authorize('update', $this->helpdeskRequest);

        $newAssignee = $value === ''
            ? null
            : $this->staff()->firstWhere('id', (int) $value);

        app(AssignRequest::class)->handle($this->helpdeskRequest, $newAssignee, Auth::user());

        unset($this->activities);

        Flux::toast(variant: 'success', text: $newAssignee === null
            ? __('Request unassigned.')
            : __('Assigned to :name.', ['name' => $newAssignee->name]));
    }

    public function updatedCategory(string $value): void
    {
        Gate::authorize('update', $this->helpdeskRequest);

        $categoryId = $value === '' ? null : (int) $value;

        if ($categoryId !== null && ! $this->categories()->contains('id', $categoryId)) {
            $this->category = (string) ($this->helpdeskRequest->category_id ?? '');

            return;
        }

        // No dedicated action: category and urgency are plain attribute
        // updates — the activity log records them via LogsActivity, and
        // no later phase needs to hook the change.
        $this->helpdeskRequest->update(['category_id' => $categoryId]);

        unset($this->activities);

        Flux::toast(variant: 'success', text: __('Category updated.'));
    }

    public function updatedUrgent(bool $value): void
    {
        Gate::authorize('update', $this->helpdeskRequest);

        $this->helpdeskRequest->update(['is_urgent' => $value]);

        unset($this->activities);

        Flux::toast(variant: 'success', text: $value
            ? __('Marked urgent.')
            : __('Urgent flag removed.'));
    }

    /**
     * Tags sync on blur: the input holds a comma-separated list, spatie's
     * syncTags reconciles it — creating new tags, detaching removed ones.
     */
    public function updatedTags(string $value): void
    {
        Gate::authorize('update', $this->helpdeskRequest);

        $names = collect(explode(',', $value))
            ->map(fn (string $name) => trim($name))
            ->filter()
            ->values();

        $this->helpdeskRequest->syncTags($names->all());

        $this->tags = $names->implode(', ');

        Flux::toast(variant: 'success', text: __('Tags updated.'));
    }

    /**
     * Timeline, newest first, authors eager-loaded (see Request::timeline()).
     *
     * @return EloquentCollection<int, Note>
     */
    #[Computed]
    public function notes(): EloquentCollection
    {
        return $this->helpdeskRequest->timeline()->get();
    }

    /**
     * The audit trail, newest first. activitylog v5 keeps each change's
     * diff in `attribute_changes` (and renamed the trait's relation to
     * activitiesAsSubject) — describeActivity() turns raw ids into names.
     *
     * @return EloquentCollection<int, Activity>
     */
    #[Computed]
    public function activities(): EloquentCollection
    {
        return $this->helpdeskRequest->activitiesAsSubject()
            ->with('causer')
            ->latest()
            ->latest('id')
            ->get();
    }

    /**
     * @return EloquentCollection<int, Category>
     */
    #[Computed]
    public function categories(): EloquentCollection
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
     * @return EloquentCollection<int, Response>
     */
    #[Computed]
    public function cannedResponses(): EloquentCollection
    {
        return Response::query()
            ->where('team_id', Auth::user()->current_team_id)
            ->orderBy('name')
            ->get();
    }

    /**
     * The customer's other requests, for the sidebar card.
     *
     * @return EloquentCollection<int, Request>
     */
    #[Computed]
    public function otherRequests(): EloquentCollection
    {
        return $this->helpdeskRequest->customer->requests()
            ->whereKeyNot($this->helpdeskRequest->id)
            ->latest()
            ->limit(5)
            ->get();
    }

    /**
     * Humanize one activity row: "changed status from Active to Resolved".
     *
     * @return array<int, string>
     */
    public function describeActivity(Activity $activity): array
    {
        if ($activity->event === 'created') {
            return [__('opened the request')];
        }

        $changes = $activity->attribute_changes ?? [];
        $attributes = $changes['attributes'] ?? [];
        $old = $changes['old'] ?? [];

        $lines = [];

        foreach ($attributes as $key => $new) {
            $lines[] = match ($key) {
                'status' => __('changed status from :old to :new', [
                    'old' => $this->statusLabel($old[$key] ?? null),
                    'new' => $this->statusLabel($new),
                ]),
                'assigned_to' => __('changed assignee from :old to :new', [
                    'old' => $this->staffName($old[$key] ?? null),
                    'new' => $this->staffName($new),
                ]),
                'category_id' => __('changed category from :old to :new', [
                    'old' => $this->categoryName($old[$key] ?? null),
                    'new' => $this->categoryName($new),
                ]),
                'is_urgent' => $new
                    ? __('flagged the request urgent')
                    : __('removed the urgent flag'),
                default => __('changed :attribute', ['attribute' => $key]),
            };
        }

        return $lines;
    }

    private function statusLabel(mixed $value): string
    {
        return RequestStatus::tryFrom((string) $value)?->label() ?? __('None');
    }

    /**
     * Resolve via the already-loaded staff collection first — falling
     * back to a query only for departed members keeps the History tab
     * from issuing a lookup per activity row (N+1).
     */
    private function staffName(mixed $value): string
    {
        if ($value === null) {
            return __('Unassigned');
        }

        return $this->staff()->firstWhere('id', (int) $value)?->name
            ?? User::query()->find((int) $value)?->name
            ?? __('a former member');
    }

    private function categoryName(mixed $value): string
    {
        if ($value === null) {
            return __('None');
        }

        return $this->categories()->firstWhere('id', (int) $value)?->name
            ?? __('a removed category');
    }

    public function render()
    {
        return $this->view()->title(__('Request #:id', ['id' => $this->helpdeskRequest->id]));
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <div class="flex items-center gap-2">
                <flux:heading size="xl" data-test="request-subject">
                    #{{ $helpdeskRequest->id }} · {{ $helpdeskRequest->subject }}
                </flux:heading>
                @if ($urgent)
                    <flux:badge color="red" data-test="urgent-badge">{{ __('Urgent') }}</flux:badge>
                @endif
            </div>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                {{ __('Opened :when via :source', ['when' => $helpdeskRequest->created_at->diffForHumans(), 'source' => $helpdeskRequest->source->label()]) }}
            </flux:text>
        </div>

        <flux:button :href="route('requests.index', ['current_team' => auth()->user()->currentTeam->slug])" variant="ghost" icon="arrow-left" wire:navigate>
            {{ __('Back to queue') }}
        </flux:button>
    </div>

    {{-- Collision banner: other staff currently on this request (presence). --}}
    @include('pages.requests.viewers', ['viewers' => $viewers])

    <div class="mt-6 grid grid-cols-1 gap-8 lg:grid-cols-3">
        <div class="space-y-8 lg:col-span-2">
            {{-- Reply box --}}
            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:radio.group wire:model.live="replyMode" variant="segmented" data-test="reply-mode">
                    <flux:radio value="public" :label="__('Public reply')" />
                    <flux:radio value="private" :label="__('Private note')" />
                </flux:radio.group>

                <form wire:submit="addNote" class="mt-4 space-y-4">
                    <flux:textarea
                        wire:model="replyBody"
                        rows="4"
                        :placeholder="$replyMode === 'private' ? __('Add an internal note — the customer never sees this.') : __('Write your reply to the customer…')"
                        data-test="reply-body"
                    />

                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <flux:select
                            wire:model.live="selectedResponse"
                            class="max-w-64"
                            data-test="response-picker"
                        >
                            <flux:select.option value="">{{ __('Insert a canned Response…') }}</flux:select.option>
                            @foreach ($this->cannedResponses as $cannedResponse)
                                <flux:select.option value="{{ $cannedResponse->id }}">{{ $cannedResponse->name }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:button variant="primary" type="submit" data-test="reply-submit">
                            {{ $replyMode === 'private' ? __('Add note') : __('Send reply') }}
                        </flux:button>
                    </div>
                </form>
            </div>

            {{-- Timeline / History tabs --}}
            <flux:tab.group>
                <flux:tabs>
                    <flux:tab name="timeline" icon="chat-bubble-left-right">{{ __('Timeline') }}</flux:tab>
                    <flux:tab name="history" icon="clock" data-test="history-tab">{{ __('History') }}</flux:tab>
                </flux:tabs>

                <flux:tab.panel name="timeline">
                    <div class="space-y-3">
                        @forelse ($this->notes as $note)
                            @if ($note->isFromCustomer())
                                <div class="me-8 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="customer-note">
                                    <div class="flex items-center gap-2">
                                        <flux:avatar size="xs" :name="$note->customer->name" />
                                        <span class="font-medium">{{ $note->customer->name }}</span>
                                        <flux:text class="text-xs text-zinc-500">{{ $note->created_at->diffForHumans() }}</flux:text>
                                    </div>
                                    <flux:text class="mt-2 whitespace-pre-line">{{ $note->body }}</flux:text>
                                </div>
                            @elseif ($note->is_private)
                                <div class="ms-8 rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-500/40 dark:bg-amber-950/40" data-test="private-note">
                                    <div class="flex items-center gap-2">
                                        <flux:icon name="lock-closed" variant="micro" class="text-amber-600 dark:text-amber-400" />
                                        <span class="font-medium">{{ $note->user?->name ?? __('Staff') }}</span>
                                        <flux:badge color="amber" size="sm" inset="top bottom">{{ __('Private') }}</flux:badge>
                                        <flux:text class="text-xs text-zinc-500">{{ $note->created_at->diffForHumans() }}</flux:text>
                                    </div>
                                    <flux:text class="mt-2 whitespace-pre-line">{{ $note->body }}</flux:text>
                                </div>
                            @else
                                <div class="ms-8 rounded-lg border border-sky-300 bg-sky-50 p-4 dark:border-sky-500/40 dark:bg-sky-950/40" data-test="staff-note">
                                    <div class="flex items-center gap-2">
                                        <flux:avatar size="xs" :name="$note->user?->name ?? __('Staff')" />
                                        <span class="font-medium">{{ $note->user?->name ?? __('Staff') }}</span>
                                        <flux:badge color="blue" size="sm" inset="top bottom">{{ __('Reply') }}</flux:badge>
                                        <flux:text class="text-xs text-zinc-500">{{ $note->created_at->diffForHumans() }}</flux:text>
                                    </div>
                                    <flux:text class="mt-2 whitespace-pre-line">{{ $note->body }}</flux:text>
                                </div>
                            @endif
                        @empty
                            <flux:text class="py-8 text-center text-zinc-500">{{ __('No notes yet.') }}</flux:text>
                        @endforelse
                    </div>
                </flux:tab.panel>

                <flux:tab.panel name="history">
                    <div class="space-y-2" data-test="history-panel">
                        @forelse ($this->activities as $activity)
                            @foreach ($this->describeActivity($activity) as $line)
                                <div class="flex items-baseline gap-2 border-b border-zinc-100 py-2 text-sm last:border-0 dark:border-zinc-800" data-test="history-entry">
                                    <span class="font-medium">{{ $activity->causer?->name ?? __('System') }}</span>
                                    <span>{{ $line }}</span>
                                    <flux:text class="ms-auto whitespace-nowrap text-xs text-zinc-500">{{ $activity->created_at->diffForHumans() }}</flux:text>
                                </div>
                            @endforeach
                        @empty
                            <flux:text class="py-8 text-center text-zinc-500">{{ __('No changes recorded yet.') }}</flux:text>
                        @endforelse
                    </div>
                </flux:tab.panel>
            </flux:tab.group>
        </div>

        {{-- Properties sidebar --}}
        <div class="space-y-6">
            <div class="space-y-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="sm">{{ __('Properties') }}</flux:heading>

                <flux:select wire:model.live="status" :label="__('Status')" data-test="property-status">
                    @foreach (App\Enums\RequestStatus::cases() as $statusOption)
                        <flux:select.option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="assignee" :label="__('Assignee')" data-test="property-assignee">
                    <flux:select.option value="">{{ __('Unassigned') }}</flux:select.option>
                    @foreach ($this->staff as $staffOption)
                        <flux:select.option value="{{ $staffOption->id }}">{{ $staffOption->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="category" :label="__('Category')" data-test="property-category">
                    <flux:select.option value="">{{ __('None') }}</flux:select.option>
                    @foreach ($this->categories as $categoryOption)
                        <flux:select.option value="{{ $categoryOption->id }}">{{ $categoryOption->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:switch wire:model.live="urgent" :label="__('Urgent')" data-test="property-urgent" />

                <flux:input
                    wire:model.blur="tags"
                    :label="__('Tags')"
                    :placeholder="__('vip, refund, …')"
                    :description="__('Comma-separated; saved on blur.')"
                    data-test="property-tags"
                />
            </div>

            {{-- Customer card --}}
            <div class="space-y-3 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="customer-card">
                <flux:heading size="sm">{{ __('Customer') }}</flux:heading>

                <div class="flex items-center gap-3">
                    <flux:avatar :name="$helpdeskRequest->customer->name" />
                    <div>
                        <div class="font-medium">{{ $helpdeskRequest->customer->name }}</div>
                        <flux:text class="text-sm text-zinc-500">{{ $helpdeskRequest->customer->email }}</flux:text>
                    </div>
                </div>

                @if ($this->otherRequests->isNotEmpty())
                    <div>
                        <flux:text class="text-xs font-medium uppercase text-zinc-500">{{ __('Other requests') }}</flux:text>
                        <ul class="mt-1 space-y-1">
                            @foreach ($this->otherRequests as $otherRequest)
                                <li>
                                    <flux:link
                                        :href="route('requests.show', ['current_team' => auth()->user()->currentTeam->slug, 'request' => $otherRequest->id])"
                                        wire:navigate
                                        class="text-sm"
                                        data-test="other-request-link"
                                    >
                                        #{{ $otherRequest->id }} · {{ $otherRequest->subject }}
                                    </flux:link>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </div>
    </div>
</section>
