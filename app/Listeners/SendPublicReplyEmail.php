<?php

namespace App\Listeners;

use App\Events\NoteAdded;
use App\Mail\PublicReplyMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * Emails a staff member's public reply to the customer.
 *
 * This is a listener rather than code inside AddNote on purpose: AddNote
 * is called by every channel (agent UI, inbound email, API, portal), and
 * none of them should need to know that public replies also go out by
 * email. The action publishes NoteAdded; side effects subscribe. Phase 6
 * triggers and Phase 7 broadcasting attach to the same event without
 * touching the write-path either.
 *
 * Laravel discovers this listener automatically — the NoteAdded type-hint
 * on handle() is the registration. ShouldQueue defers the actual send to
 * the queue worker, so the agent's "post reply" interaction never waits
 * on an SMTP/API round-trip.
 */
class SendPublicReplyEmail implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     */
    public function handle(NoteAdded $event): void
    {
        $note = $event->note;

        // Three guards: private notes are internal-only, customer-authored
        // notes ARE the customer's words (never echoed back), and a reply
        // needs somewhere to go.
        if ($note->is_private || $note->user_id === null) {
            return;
        }

        // A request always has a customer (the FK is non-nullable), but a
        // customer created from a malformed inbound address could carry an
        // empty email — there's nowhere to send in that case.
        $customer = $note->request->customer;

        if ($customer->email === '') {
            return;
        }

        Mail::to($customer->email)->send(new PublicReplyMail($note));

        // Persist the Message-ID we just sent under, so the customer's
        // eventual reply (carrying it in In-Reply-To/References) can be
        // matched back to this request. The id is deterministic — derived
        // from the note's primary key — so a retried queue job stores the
        // same value it re-sends with.
        $note->update(['message_id' => PublicReplyMail::messageIdFor($note)]);
    }
}
