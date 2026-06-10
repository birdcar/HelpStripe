<?php

use App\Models\Mailbox;
use App\Models\Note;
use App\Models\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/*
 * mail:replay is the offline demo path: it runs recorded fixtures through
 * the exact same ProcessInboundEmail the live webhook queues. These tests
 * confirm a replay creates/matches requests without touching the network
 * (the command fakes the Resend reads internally) and that the attachment
 * fixture lands a file on the note.
 */

beforeEach(function () {
    Mail::fake();
    // The seeded demo addresses; fixtures are addressed to support@/billing@.
    Mailbox::factory()->create(['address' => 'support@helpstripe.test']);
});

test('replaying a single fixture creates a request', function () {
    $this->artisan('mail:replay', ['fixture' => 'inbound-new'])
        ->assertSuccessful();

    $request = Request::query()->latest('id')->firstOrFail();

    expect($request->subject)->toBe("Can't sign in to the dashboard")
        ->and($request->source->value)->toBe('email')
        ->and($request->notes()->count())->toBe(1);
});

test('replaying every fixture in order threads the reply onto the new request', function () {
    Mailbox::factory()->create(['address' => 'billing@helpstripe.test']);

    // No argument → replays inbound-attachment, inbound-new, inbound-reply
    // (alphabetical). inbound-reply references inbound-new's message id, so
    // it threads rather than opening a third request.
    $this->artisan('mail:replay')->assertSuccessful();

    // inbound-new + inbound-attachment open requests; inbound-reply threads.
    expect(Request::query()->count())->toBe(2);

    $signIn = Request::query()->where('subject', "Can't sign in to the dashboard")->firstOrFail();
    expect($signIn->notes()->count())->toBe(2);
});

test('replaying the attachment fixture attaches the file to the note', function () {
    Storage::fake();
    Mailbox::factory()->create(['address' => 'billing@helpstripe.test']);

    $this->artisan('mail:replay', ['fixture' => 'inbound-attachment'])
        ->assertSuccessful();

    $note = Note::query()->latest('id')->firstOrFail();

    expect($note->getMedia('attachments'))->toHaveCount(1)
        ->and($note->getFirstMedia('attachments')->file_name)->toBe('receipt.pdf');
});

test('an unknown fixture name fails gracefully', function () {
    $this->artisan('mail:replay', ['fixture' => 'does-not-exist'])
        ->expectsOutputToContain('Fixture not found: does-not-exist')
        ->assertSuccessful();

    expect(Request::query()->count())->toBe(0);
});
