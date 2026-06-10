<?php

use App\Actions\Requests\CreateRequest;
use App\Enums\RequestSource;
use App\Mail\NewRequestConfirmationMail;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Request;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

/*
 * The public submit form — the portal intake channel.
 *
 * Reuses the same CreateRequest action the agent UI, email pipeline, and
 * API call, so RequestCreated fires and the opening note lands the same
 * way regardless of channel; the only difference is RequestSource::Portal.
 * After creating the request this component sends NewRequestConfirmationMail
 * exactly as ProcessInboundEmail does — the confirmation (not this page)
 * carries the access key, which forces the email round-trip that mirrors
 * real HelpSpot and makes the demo realistic.
 *
 * No customer account exists or is created beyond the Customer row, keyed
 * by email (deduped case-insensitively, matching the email + API channels).
 */
new #[Layout('layouts::portal')] #[Title('Submit a request')] class extends Component {
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|email|max:255')]
    public string $email = '';

    // Optional: customers may not know which bucket their question belongs
    // in. '' means "no category" — staff triage it later.
    #[Validate('nullable|integer')]
    public string $category = '';

    #[Validate('required|string|max:255')]
    public string $subject = '';

    #[Validate('required|string|max:5000')]
    public string $body = '';

    // After a successful submit the form is replaced by a confirmation
    // panel showing this number. The access key is NOT shown here — it goes
    // out by email only.
    public ?int $submittedRequestId = null;

    /**
     * The installation's categories, offered as optional triage hints.
     *
     * The portal has no tenant context (customers aren't team members), so
     * it reads the installation team's categories — the same single-team
     * fallback the API and inbound pipeline use.
     *
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        $team = $this->installationTeam();

        if ($team === null) {
            return new Collection;
        }

        return Category::query()
            ->where('team_id', $team->id)
            ->orderBy('name')
            ->get();
    }

    public function submit(CreateRequest $createRequest): void
    {
        $validated = $this->validate();

        $team = $this->installationTeam();
        abort_unless($team !== null, 503, __('Support is not available right now.'));

        // A category id from another installation (or a stale option) is
        // dropped rather than trusted — the select is a hint, not a
        // security boundary.
        $categoryId = $validated['category'] === ''
            ? null
            : (int) $validated['category'];

        if ($categoryId !== null && ! $this->categories->contains('id', $categoryId)) {
            $categoryId = null;
        }

        $customer = $this->resolveCustomer($team, $validated['email'], $validated['name']);

        $request = $createRequest->handle(
            $customer,
            $validated['subject'],
            $validated['body'],
            RequestSource::Portal,
            ['category_id' => $categoryId],
        );

        // Tell the customer we have it: the request number threads any
        // reply (via the [#id] subject token), and the access key inside
        // the mail is how they get back into the portal. Queued so the
        // form responds instantly; the demo runs a queue worker.
        Mail::to($customer->email)->queue(new NewRequestConfirmationMail($request));

        $this->submittedRequestId = $request->id;
    }

    /**
     * Find the customer by email (case-insensitively) or create them.
     *
     * Mirrors RequestController::resolveCustomer and the inbound pipeline:
     * `Pat@Example.com` and `pat@example.com` are the same person. An
     * existing customer keeps their original display name — a new name on a
     * later submission doesn't overwrite it (documented in the tour doc).
     */
    private function resolveCustomer(Team $team, string $email, string $name): Customer
    {
        $normalizedEmail = Str::lower($email);

        $existing = Customer::query()
            ->where('team_id', $team->id)
            ->whereRaw('lower(email) = ?', [$normalizedEmail])
            ->first();

        return $existing ?? Customer::create([
            'team_id' => $team->id,
            'name' => $name,
            'email' => $normalizedEmail,
        ]);
    }

    /**
     * The single team this installation represents (see DemoSeeder and
     * StoreRequestRequest::installationTeam — the same fallback).
     */
    private function installationTeam(): ?Team
    {
        return Team::query()->orderBy('id')->first();
    }
}; ?>

<div>
    @if ($submittedRequestId !== null)
        <div class="rounded-lg border border-green-300 bg-green-50 p-6 dark:border-green-500/40 dark:bg-green-950/40" data-test="portal-submit-confirmation">
            <flux:heading size="lg">{{ __('Request received') }}</flux:heading>
            <flux:text class="mt-2">
                {{ __('Your request number is :number.', ['number' => '#'.$submittedRequestId]) }}
            </flux:text>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                {{ __('We have emailed your access key to :email — use it with your email address to check status any time.', ['email' => $email]) }}
            </flux:text>

            <div class="mt-4 flex gap-3">
                <flux:button :href="route('portal.lookup')" variant="primary" wire:navigate>
                    {{ __('Check status') }}
                </flux:button>
                <flux:button :href="route('portal.home')" variant="ghost" wire:navigate>
                    {{ __('Back to support') }}
                </flux:button>
            </div>
        </div>
    @else
        <flux:heading size="xl">{{ __('Submit a request') }}</flux:heading>
        <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
            {{ __('Tell us what you need. We will email you an access key to track it.') }}
        </flux:text>

        <form wire:submit="submit" class="mt-6 flex flex-col gap-4">
            <flux:input
                wire:model="name"
                :label="__('Your name')"
                required
                data-test="portal-submit-name"
            />

            <flux:input
                wire:model="email"
                type="email"
                :label="__('Email address')"
                required
                data-test="portal-submit-email"
            />

            @if ($this->categories->isNotEmpty())
                <flux:select wire:model="category" :label="__('Category (optional)')" data-test="portal-submit-category">
                    <flux:select.option value="">{{ __('Not sure') }}</flux:select.option>
                    @foreach ($this->categories as $option)
                        <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:input
                wire:model="subject"
                :label="__('Subject')"
                required
                data-test="portal-submit-subject"
            />

            <flux:textarea
                wire:model="body"
                :label="__('How can we help?')"
                rows="6"
                required
                data-test="portal-submit-body"
            />

            <div>
                <flux:button type="submit" variant="primary" data-test="portal-submit-button">
                    {{ __('Submit request') }}
                </flux:button>
            </div>
        </form>
    @endif
</div>
