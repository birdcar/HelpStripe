<?php

namespace App\Actions\Requests;

use App\Enums\RequestSource;
use App\Events\NoteAdded;
use App\Models\Customer;
use App\Models\Note;
use App\Models\Request;
use App\Models\User;

/**
 * Add a timeline entry to a request: a staff public reply, a staff
 * private note, or a customer reply (used by Phases 3 and 4).
 *
 * Owns the first-response rule: `first_responded_at` is stamped exactly
 * once, by the first staff *public* reply — private notes don't count
 * because the customer never saw them. Phase 8's SLA report reads this
 * column, so the rule lives here and nowhere else.
 */
class AddNote
{
    /**
     * Append a note to the request's timeline.
     */
    public function handle(
        Request $request,
        User|Customer $author,
        string $body,
        bool $isPrivate = false,
        RequestSource $source = RequestSource::Agent,
    ): Note {
        $note = $request->notes()->create([
            'user_id' => $author instanceof User ? $author->id : null,
            'customer_id' => $author instanceof Customer ? $author->id : null,
            'body' => $body,
            'is_private' => $isPrivate,
            'source' => $source,
        ]);

        if ($author instanceof User && ! $isPrivate) {
            // whereNull() makes the stamp concurrency-safe: two agents
            // replying at once race to a single UPDATE … WHERE
            // first_responded_at IS NULL — only one row ever wins, so the
            // SLA metric can't be overwritten by the slower reply.
            Request::query()
                ->whereKey($request->id)
                ->whereNull('first_responded_at')
                ->update(['first_responded_at' => now()]);

            $request->refresh();
        }

        NoteAdded::dispatch($note);

        return $note;
    }
}
