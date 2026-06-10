<?php

use App\Actions\Requests\AddNote;
use App\Mail\PublicReplyMail;
use App\Models\Mailbox;
use App\Models\Note;
use App\Models\Request;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/*
 * The listener under test (SendPublicReplyEmail) is queued, but the test
 * environment runs QUEUE_CONNECTION=sync, so AddNote → NoteAdded →
 * listener → Mail::send all happens inline — Mail::fake() observes the
 * send directly.
 */

test('a public staff reply is emailed to the customer from the mailbox address', function () {
    Mail::fake();

    $mailbox = Mailbox::factory()->create(['address' => 'support@helpstripe.test', 'name' => 'Support']);
    $request = Request::factory()->create([
        'team_id' => $mailbox->team_id,
        'mailbox_id' => $mailbox->id,
        'subject' => 'Printer on fire',
    ]);
    $staff = User::factory()->create();

    app(AddNote::class)->handle($request, $staff, 'Have you tried water?');

    Mail::assertSent(PublicReplyMail::class, function (PublicReplyMail $mail) use ($request) {
        return $mail->hasTo($request->customer->email)
            && $mail->hasFrom('support@helpstripe.test')
            && $mail->hasReplyTo('support@helpstripe.test')
            && $mail->hasSubject("Re: Printer on fire [#{$request->id}]");
    });
});

test('the sent reply persists its generated message id on the note', function () {
    Mail::fake();

    $request = Request::factory()->create();
    $staff = User::factory()->create();

    $note = app(AddNote::class)->handle($request, $staff, 'Reply body');

    expect($note->refresh()->message_id)->toBe("note-{$note->id}@helpstripe.test");
});

test('a private note does not email the customer', function () {
    Mail::fake();

    $request = Request::factory()->create();
    $staff = User::factory()->create();

    app(AddNote::class)->handle($request, $staff, 'Internal musings', isPrivate: true);

    Mail::assertNothingSent();
});

test('a customer-authored note does not email the customer', function () {
    Mail::fake();

    $request = Request::factory()->create();

    app(AddNote::class)->handle($request, $request->customer, 'Customer follow-up');

    Mail::assertNothingSent();
});

test('reply headers carry a deterministic message id and chain prior notes into references', function () {
    $request = Request::factory()->create();
    // The customer's opening email, as the inbound pipeline would store it.
    $opening = Note::factory()->fromCustomer()->create([
        'request_id' => $request->id,
        'message_id' => 'customer-original@example.com',
    ]);
    $staff = User::factory()->create();

    $firstReply = app(AddNote::class)->handle($request, $staff, 'First reply');
    $secondReply = app(AddNote::class)->handle($request, $staff, 'Second reply');

    $headers = (new PublicReplyMail($secondReply))->headers();

    expect($headers->messageId)->toBe("note-{$secondReply->id}@helpstripe.test")
        ->and($headers->references)->toBe([
            'customer-original@example.com',
            "note-{$firstReply->id}@helpstripe.test",
        ]);
});

test('a reply on a request without a mailbox falls back to the app-wide from address', function () {
    Mail::fake();

    $request = Request::factory()->create(['mailbox_id' => null]);
    $staff = User::factory()->create();

    app(AddNote::class)->handle($request, $staff, 'Reply body');

    Mail::assertSent(PublicReplyMail::class, function (PublicReplyMail $mail) {
        return $mail->hasFrom(config('mail.from.address'));
    });
});
