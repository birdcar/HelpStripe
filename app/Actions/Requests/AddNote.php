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
     *
     * `$messageId` is the email Message-ID this note arrived under (inbound
     * customer replies) — stored so future replies referencing it can be
     * matched back to this request. `$attachments` are files already
     * downloaded to local temp paths (the email pipeline fetches them from
     * Resend before calling in); they're moved into the note's media
     * collection here so every channel attaches files the same way.
     *
     * @param  array<int, array{path: string, name: string}>  $attachments
     */
    public function handle(
        Request $request,
        User|Customer $author,
        string $body,
        bool $isPrivate = false,
        RequestSource $source = RequestSource::Agent,
        ?string $messageId = null,
        array $attachments = [],
    ): Note {
        $note = $request->notes()->create([
            'user_id' => $author instanceof User ? $author->id : null,
            'customer_id' => $author instanceof Customer ? $author->id : null,
            'body' => $body,
            'is_private' => $isPrivate,
            'source' => $source,
            'message_id' => $messageId,
        ]);

        foreach ($attachments as $attachment) {
            // addMedia() MOVES the file into the media disk — exactly right
            // for temp downloads. usingFileName() keeps the sender's
            // original name instead of the temp path's random one.
            $note->addMedia($attachment['path'])
                ->usingFileName($attachment['name'])
                ->toMediaCollection('attachments');
        }

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
