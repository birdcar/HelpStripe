<?php

namespace App\Events;

use App\Models\Note;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a note lands on a request's timeline: staff public reply,
 * staff private note, or a customer reply.
 *
 * This event wears two hats. Server-side, the queued SendPublicReplyEmail
 * listener (Phase 3) reacts to it. Client-side (Phase 7), it ALSO broadcasts
 * over the websocket so any open detail page for the same request refreshes
 * its timeline — the live half of collision detection: one agent replies,
 * the other watching the same ticket sees it appear without reloading.
 */
class NoteAdded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public Note $note)
    {
        //
    }

    /**
     * The channel to broadcast on — the SAME presence channel the detail
     * page joins for viewer tracking. Reusing it means one subscription
     * carries both signals (who's here + what changed); authorization is
     * already gated to the request's team in routes/channels.php.
     */
    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('request.'.$this->note->request_id);
    }

    /**
     * The payload that rides the wire.
     *
     * Deliberately only the note id — NOT the body. Private notes never
     * leave the database over the websocket: the client receives "note N
     * changed" and re-queries through the authorized component, which
     * applies the same visibility rules the page already enforces. This is
     * the named guard against a future dev fattening the payload and leaking
     * an internal note's text to every connected browser.
     *
     * @return array{note_id: int}
     */
    public function broadcastWith(): array
    {
        return ['note_id' => $this->note->id];
    }
}
