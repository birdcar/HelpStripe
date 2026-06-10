<?php

use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');

        // The helpdesk proper. `{request}` route-model-binds to
        // App\Models\Request (the ticket, not the HTTP request); the show
        // page's RequestPolicy check keeps cross-team ids out.
        Route::livewire('requests', 'pages::requests.index')->name('requests.index');
        Route::livewire('requests/{request}', 'pages::requests.show')->name('requests.show');
    });

Route::middleware(['auth'])->group(function () {
    Route::livewire('invitations/{invitation}/accept', 'pages::teams.accept-invitation')->name('invitations.accept');
});

require __DIR__.'/settings.php';
