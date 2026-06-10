<?php

use App\Models\Request;
use App\Models\Team;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

/*
 * The manual access path to a request's status: email + access key.
 *
 * This is the non-signed-link way in. On a match we set a session flag
 * (portal.verified.{id}) and redirect to the unsigned status route, which
 * checks that flag — so the customer isn't re-prompted on every reply.
 * The session flag is deliberately simpler than real auth; the tour doc
 * names that tradeoff.
 *
 * On a miss we return ONE generic error regardless of which field was
 * wrong (unknown email vs wrong key) — an enumeration-resistance lesson:
 * a field-specific hint would let an attacker confirm which emails exist.
 */
new #[Layout('layouts::portal')] #[Title('Check a request')] class extends Component {
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $accessKey = '';

    public function lookup(): mixed
    {
        $validated = $this->validate();

        $team = Team::query()->orderBy('id')->first();

        $request = $team === null
            ? null
            : Request::query()
                ->where('team_id', $team->id)
                // access_key is an exact, case-sensitive match (it's a
                // random secret, not a human-typed identifier). Email is
                // matched case-insensitively, same as every other channel.
                ->where('access_key', $validated['accessKey'])
                ->whereHas(
                    'customer',
                    fn ($query) => $query->whereRaw('lower(email) = ?', [Str::lower($validated['email'])]),
                )
                ->first();

        if ($request === null) {
            // One message for every failure mode — no field-level hint.
            $this->addError('lookup', __("We couldn't find a matching request. Check your email and access key and try again."));

            return null;
        }

        // Grant verified access for this request, then hand off to the
        // status page on its unsigned route (the signed route is only for
        // the email link).
        Session::put($this->verifiedKey($request->id), true);

        return $this->redirectRoute('portal.status.show', ['request' => $request->id], navigate: true);
    }

    /**
     * The session key that marks a request as verified for this visitor.
     */
    private function verifiedKey(int $requestId): string
    {
        return "portal.verified.{$requestId}";
    }
}; ?>

<div class="mx-auto max-w-md">
    <flux:heading size="xl">{{ __('Check a request') }}</flux:heading>
    <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
        {{ __('Enter the email you used and the access key from your confirmation email.') }}
    </flux:text>

    <form wire:submit="lookup" class="mt-6 flex flex-col gap-4">
        @error('lookup')
            <flux:callout variant="danger" icon="exclamation-triangle" data-test="portal-lookup-error">
                {{ $message }}
            </flux:callout>
        @enderror

        <flux:input
            wire:model="email"
            type="email"
            :label="__('Email address')"
            required
            data-test="portal-lookup-email"
        />

        <flux:input
            wire:model="accessKey"
            :label="__('Access key')"
            required
            data-test="portal-lookup-key"
        />

        <div>
            <flux:button type="submit" variant="primary" data-test="portal-lookup-button">
                {{ __('Check status') }}
            </flux:button>
        </div>
    </form>
</div>
