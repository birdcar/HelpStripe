{{--
    The public portal layout — the multiple-layouts lesson in practice.

    Compare with layouts/app.blade.php (the authed app shell): no sidebar,
    no team switcher, no user menu — customers are anonymous. Portal page
    components opt in via #[Layout('layouts::portal')].

    Created by Phase 5 (knowledge base); Phase 4 (self-service portal)
    extended it with the submit/check-status navigation — the Route::has()
    guards keep the two phases order-independent, so each nav link only
    appears once its phase has shipped.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="flex min-h-screen flex-col bg-white dark:bg-zinc-800">
        <header class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mx-auto flex w-full max-w-4xl items-center justify-between px-6 py-4">
                <a
                    href="{{ Route::has('portal.home') ? route('portal.home') : url('/') }}"
                    class="font-semibold text-zinc-900 dark:text-white"
                    wire:navigate
                >
                    {{ config('app.name') }} {{ __('Support') }}
                </a>

                <nav class="flex items-center gap-4 text-sm">
                    @if (Route::has('portal.submit'))
                        <flux:link :href="route('portal.submit')" wire:navigate>
                            {{ __('Submit a request') }}
                        </flux:link>
                    @endif

                    @if (Route::has('portal.lookup'))
                        <flux:link :href="route('portal.lookup')" wire:navigate>
                            {{ __('Check a request') }}
                        </flux:link>
                    @endif

                    @if (Route::has('portal.kb.index'))
                        <flux:link :href="route('portal.kb.index')" wire:navigate>
                            {{ __('Knowledge Base') }}
                        </flux:link>
                    @endif
                </nav>
            </div>
        </header>

        <main class="mx-auto w-full max-w-4xl grow px-6 py-10">
            {{ $slot }}
        </main>

        <footer class="border-t border-zinc-200 dark:border-zinc-700">
            <div class="mx-auto w-full max-w-4xl px-6 py-4">
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('Powered by :app', ['app' => config('app.name')]) }}
                </flux:text>
            </div>
        </footer>

        @fluxScripts
    </body>
</html>
