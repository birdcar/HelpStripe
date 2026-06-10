<?php

use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// Resend posts inbound email events here. Route::webhooks() is a macro
// registered by spatie/laravel-webhook-client: one POST route wired to
// the package's controller, which validates the svix signature, stores
// the call, and queues ProcessInboundEmail — see config/webhook-client.php
// (config name 'resend' is the second argument). The path is CSRF-exempt
// in bootstrap/app.php: external senders can't carry a CSRF token.
Route::webhooks('webhooks/resend', 'resend');

// The public knowledge base — no auth, no team in the URL: customers
// browse THE installation's published books. Nested slugs resolve within
// their parent via scopeBindings(): `{chapter:slug}` is looked up through
// $book->chapters() and `{page:slug}` through $chapter->pages(), so two
// books can each have an "introduction" chapter without cross-resolving.
//
// Registration order matters twice here: this group must precede the
// `{current_team}` group below (or /portal would be captured as a team
// slug), and kb/search must precede kb/{book:slug} (or "search" would be
// parsed as a book slug).
Route::prefix('portal')->name('portal.')->group(function () {
    Route::livewire('kb', 'pages::portal.kb.index')->name('kb.index');
    Route::livewire('kb/search', 'pages::portal.kb.search')->name('kb.search');
    Route::livewire('kb/{book:slug}', 'pages::portal.kb.book')->name('kb.book');
    Route::livewire('kb/{book:slug}/{chapter:slug}/{page:slug}', 'pages::portal.kb.page')
        ->name('kb.page')
        ->scopeBindings();
});

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');

        // The helpdesk proper. `{request}` route-model-binds to
        // App\Models\Request (the ticket, not the HTTP request); the show
        // page's RequestPolicy check keeps cross-team ids out.
        Route::livewire('requests', 'pages::requests.index')->name('requests.index');
        Route::livewire('requests/{request}', 'pages::requests.show')->name('requests.show');

        // Knowledge base manager. Contrast with the request routes above:
        // requests are gated by *membership* (any staff member works the
        // queue), while the KB manager is gated by a spatie *permission* —
        // `can:` middleware resolves 'manage knowledge base' through the
        // Gate, where laravel-permission registered it as an ability.
        Route::middleware('can:manage knowledge base')->group(function () {
            Route::livewire('kb', 'pages::kb.index')->name('kb.index');
            Route::livewire('kb/{book}', 'pages::kb.book')->name('kb.book');
            Route::livewire('kb/pages/{page}', 'pages::kb.edit-page')->name('kb.edit-page');
        });

        // Reporting — the same permission-gating pattern as the KB manager,
        // behind the 'view reports' ability. The sidebar nav item mirrors
        // this middleware with `@can('view reports')`, so staff without the
        // permission never see a link they'd 403 on.
        Route::middleware('can:view reports')->group(function () {
            Route::livewire('reports', 'pages::reports.index')->name('reports.index');
        });
    });

Route::middleware(['auth'])->group(function () {
    Route::livewire('invitations/{invitation}/accept', 'pages::teams.accept-invitation')->name('invitations.accept');
});

require __DIR__.'/settings.php';
