<?php

namespace App\Mail;

use App\Models\Note;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * A staff member's public reply, delivered to the customer's inbox.
 *
 * The interesting part is headers(): email threading is built on three
 * RFC 5322 headers. Every message carries a unique Message-ID; replies
 * carry In-Reply-To (the parent's id) and References (the whole chain).
 * Mail clients group messages into conversations by walking these — so
 * by minting a deterministic Message-ID per note and chaining prior
 * note ids into References, our replies land inside the customer's
 * existing thread, and THEIR replies come back carrying ids we can
 * match (see Request::findForInbound()).
 */
class PublicReplyMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(public Note $note)
    {
        //
    }

    /**
     * Get the message envelope.
     *
     * From/reply-to is the request's mailbox address, so the customer
     * replies to support@… (which Resend receives) — never to a staff
     * member's personal address. Requests that didn't arrive through a
     * mailbox fall back to the app-wide from address.
     */
    public function envelope(): Envelope
    {
        $request = $this->note->request;

        $address = $request->mailbox->address ?? config('mail.from.address');
        $name = $request->mailbox->name ?? config('mail.from.name');

        return new Envelope(
            from: new Address($address, $name),
            replyTo: [new Address($address, $name)],
            // The [#id] subject token is belt-and-braces threading: some
            // mail clients strip References, but almost all preserve the
            // subject line on reply — the inbound matcher falls back to it.
            subject: sprintf('Re: %s [#%d]', $request->subject, $request->id),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail.public-reply',
            with: [
                'note' => $this->note,
                'request' => $this->note->request,
            ],
        );
    }

    /**
     * Get the message headers.
     *
     * Laravel's Headers value object takes ids WITHOUT angle brackets —
     * Symfony Mailer adds them on the wire. References chains every
     * prior note that has a message id (inbound customer messages and
     * our earlier replies alike), oldest first, per RFC 5322.
     */
    public function headers(): Headers
    {
        $priorMessageIds = $this->note->request->notes()
            ->whereNotNull('message_id')
            ->whereKeyNot($this->note->id)
            ->where('id', '<', $this->note->id)
            ->orderBy('id')
            ->pluck('message_id')
            ->all();

        return new Headers(
            messageId: self::messageIdFor($this->note),
            references: $priorMessageIds,
        );
    }

    /**
     * The deterministic Message-ID minted for a note.
     *
     * Derived from the note's primary key so retries of the queued
     * listener regenerate the identical id — and so the listener can
     * persist it on the note without round-tripping the sent message.
     */
    public static function messageIdFor(Note $note): string
    {
        return sprintf('note-%d@%s', $note->id, config('helpstripe.inbound_domain'));
    }
}
