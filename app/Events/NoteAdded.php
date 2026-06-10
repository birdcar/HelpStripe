<?php

namespace App\Events;

use App\Models\Note;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a note lands on a request's timeline: staff public reply,
 * staff private note, or (in later phases) a customer reply.
 *
 * Plain event for now — Phase 6 triggers and Phase 7 live-timeline
 * broadcasting subscribe without changing the publisher.
 */
class NoteAdded
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public Note $note)
    {
        //
    }
}
