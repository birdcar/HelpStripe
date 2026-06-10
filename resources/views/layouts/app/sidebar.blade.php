<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <livewire:team-switcher />

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Platform')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>

                    @php
                        // Open-request count for the badge. One COUNT query per
                        // page render is fine here; values are status strings
                        // because whereIn doesn't consult Eloquent casts.
                        $openRequestCount = \App\Models\Request::query()
                            ->where('team_id', auth()->user()->current_team_id)
                            ->whereIn('status', array_map(fn ($status) => $status->value, \App\Enums\RequestStatus::open()))
                            ->count();
                    @endphp
                    <flux:sidebar.item
                        icon="inbox"
                        :href="route('requests.index')"
                        :current="request()->routeIs('requests.*')"
                        :badge="$openRequestCount ?: null"
                        wire:navigate
                    >
                        {{ __('Queue') }}
                    </flux:sidebar.item>

                    {{-- Visible only with the spatie permission — the nav
                         mirrors the `can:` middleware on the kb routes, so
                         staff without the permission never see a link they
                         would 403 on. --}}
                    @can('manage knowledge base')
                        <flux:sidebar.item
                            icon="book-open"
                            :href="route('kb.index')"
                            :current="request()->routeIs('kb.*')"
                            wire:navigate
                        >
                            {{ __('Knowledge Books') }}
                        </flux:sidebar.item>
                    @endcan

                    {{-- Same pattern as the KB item: visible only with the
                         'view reports' permission, mirroring the `can:view
                         reports` middleware on the reports route. --}}
                    @can('view reports')
                        <flux:sidebar.item
                            icon="chart-bar"
                            :href="route('reports.index')"
                            :current="request()->routeIs('reports.*')"
                            wire:navigate
                        >
                            {{ __('Reports') }}
                        </flux:sidebar.item>
                    @endcan
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                <flux:sidebar.item icon="folder-git-2" href="https://github.com/laravel/livewire-starter-kit" target="_blank">
                    {{ __('Repository') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="book-open-text" href="https://laravel.com/docs/starter-kits#livewire" target="_blank">
                    {{ __('Documentation') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        <livewire:create-team-modal />

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
