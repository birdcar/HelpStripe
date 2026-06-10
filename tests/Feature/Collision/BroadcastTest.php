<?php

use App\Actions\Requests\AddNote;
use App\Enums\RequestSource;
use App\Events\NoteAdded;
use App\Models\Note;
use App\Models\Request;
use App\Models\User;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\Event;

/*
 * The live half of collision detection: when a note is added, NoteAdded
 * broadcasts on the request's presence channel so other open detail pages
 * refresh. These tests pin the broadcast contract — channel, payload shape,
 * and the privacy guard — without a running websocket server.
 */

test('NoteAdded is a broadcast event', function () {
    expect(new NoteAdded(Note::factory()->create()))
        ->toBeInstanceOf(ShouldBroadcast::class);
});

test('NoteAdded broadcasts on the request presence channel', function () {
    $request = Request::factory()->create();
    $note = Note::factory()->create(['request_id' => $request->id]);

    $channel = (new NoteAdded($note))->broadcastOn();

    expect($channel)->toBeInstanceOf(PresenceChannel::class)
        // PresenceChannel prefixes the name with `presence-` internally;
        // the logical channel is `request.{id}` — the one the detail page
        // joins and routes/channels.php authorizes.
        ->and($channel->name)->toBe("presence-request.{$request->id}");
});

test('the broadcast payload carries only the note id, never the body', function () {
    // A PRIVATE note: its body must never ride the websocket. The payload
    // is the note id alone; clients re-query through the authorized
    // component, which is where visibility rules are enforced.
    $note = Note::factory()->create([
        'is_private' => true,
        'body' => 'Internal: customer is a known chargeback risk.',
    ]);

    $payload = (new NoteAdded($note))->broadcastWith();

    expect($payload)->toBe(['note_id' => $note->id])
        ->and($payload)->not->toHaveKey('body');
});

test('adding a note dispatches NoteAdded for broadcasting', function () {
    Event::fake([NoteAdded::class]);

    $request = Request::factory()->create();
    $staff = User::factory()->create();

    app(AddNote::class)->handle($request, $staff, 'A public reply.', source: RequestSource::Agent);

    Event::assertDispatched(NoteAdded::class, function (NoteAdded $event) use ($request) {
        return $event->note->request_id === $request->id
            && $event->broadcastWith() === ['note_id' => $event->note->id];
    });
});
