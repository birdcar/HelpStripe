<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The portal landing page: two CTAs (submit a request, check a request)
 * and a conditional knowledge-base teaser.
 *
 * Near-static content — no component state. It still goes through a
 * Livewire SFC (rather than a plain Route::view) so it shares the portal
 * layout and wire:navigate transitions with the rest of the portal.
 *
 * The KB section renders only when Phase 5 has shipped its routes
 * (Route::has('portal.kb.index')) — Phases 4 and 5 stay order-independent,
 * exactly like the nav links in layouts/portal.blade.php.
 */
new #[Layout('layouts::portal')] #[Title('Support')] class extends Component {}; ?>

<div>
    <div class="text-center">
        <flux:heading size="xl">{{ __('How can we help?') }}</flux:heading>
        <flux:text class="mt-2 text-zinc-500 dark:text-zinc-400">
            {{ __('Submit a request or check on one you already sent.') }}
        </flux:text>
    </div>

    <div class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <a
            href="{{ route('portal.submit') }}"
            wire:navigate
            class="flex flex-col rounded-lg border border-zinc-200 bg-white p-6 transition hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600"
            data-test="portal-submit-cta"
        >
            <flux:icon name="pencil-square" class="text-zinc-400 dark:text-zinc-500" />
            <flux:heading size="lg" class="mt-3">{{ __('Submit a request') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                {{ __('Tell us what you need and we will email you an access key to track it.') }}
            </flux:text>
        </a>

        <a
            href="{{ route('portal.lookup') }}"
            wire:navigate
            class="flex flex-col rounded-lg border border-zinc-200 bg-white p-6 transition hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600"
            data-test="portal-lookup-cta"
        >
            <flux:icon name="magnifying-glass" class="text-zinc-400 dark:text-zinc-500" />
            <flux:heading size="lg" class="mt-3">{{ __('Check a request') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                {{ __('Enter your email and access key to see the latest status and reply.') }}
            </flux:text>
        </a>
    </div>

    @if (Route::has('portal.kb.index'))
        <div class="mt-10 rounded-lg border border-zinc-200 bg-zinc-50 p-6 dark:border-zinc-700 dark:bg-zinc-900" data-test="portal-kb-teaser">
            <flux:heading size="lg">{{ __('Looking for a quick answer?') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                {{ __('Our knowledge base has guides for common questions — no ticket required.') }}
            </flux:text>
            <flux:link :href="route('portal.kb.index')" wire:navigate class="mt-3 inline-block">
                {{ __('Browse the knowledge base') }}
            </flux:link>
        </div>
    @endif
</div>
