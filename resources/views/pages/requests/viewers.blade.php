{{--
    Collision banner — "Riley is also viewing this request."

    Presentational partial only: it renders the $viewers the parent ⚡show
    component maintains from the presence channel's here/joining/leaving
    events. It deliberately does NOT join the channel itself — a second
    subscription per browser would register the same user twice and the
    roster would double-count. Presence lives in exactly one place (⚡show);
    this file just draws it.

    $viewers is an array of ['id' => int, 'name' => string], already filtered
    to exclude the current user (you never collide with yourself).
--}}
@if (! empty($viewers))
    <div
        class="mt-4 flex items-center gap-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 dark:border-amber-500/40 dark:bg-amber-950/40"
        data-test="collision-banner"
        wire:transition
    >
        <flux:avatar.group>
            @foreach ($viewers as $viewer)
                <flux:avatar size="sm" :name="$viewer['name']" />
            @endforeach
        </flux:avatar.group>

        <flux:text class="text-sm text-amber-800 dark:text-amber-200">
            @if (count($viewers) === 1)
                {{ __(':name is also viewing this request', ['name' => $viewers[array_key_first($viewers)]['name']]) }}
            @else
                {{ __(':count people are also viewing this request', ['count' => count($viewers)]) }}
            @endif
        </flux:text>
    </div>
@endif
