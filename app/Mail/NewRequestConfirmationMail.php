<?php

namespace App\Mail;

use App\Models\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * "We got your request" — sent to the customer when a new request is
 * opened from an inbound email (and reused by Phase 4's portal).
 *
 * Carries the request number and the portal access key: email + key is
 * how customers authenticate to the self-service portal (customers have
 * no user accounts — see the Customer model). The [#id] subject token
 * means even a reply to THIS mail threads back onto the right request.
 */
class NewRequestConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(public Request $request)
    {
        //
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $address = $this->request->mailbox->address ?? config('mail.from.address');
        $name = $this->request->mailbox->name ?? config('mail.from.name');

        return new Envelope(
            from: new Address($address, $name),
            replyTo: [new Address($address, $name)],
            subject: sprintf('We received your request: %s [#%d]', $this->request->subject, $this->request->id),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail.new-request-confirmation',
            with: [
                'request' => $this->request,
            ],
        );
    }

    /**
     * Get the message headers.
     *
     * References the customer's original message id (stored on the
     * opening note) so this confirmation lands in the same conversation
     * they started, rather than opening a second thread.
     */
    public function headers(): Headers
    {
        $openingMessageId = $this->request->notes()
            ->whereNotNull('message_id')
            ->orderBy('id')
            ->value('message_id');

        return new Headers(
            messageId: sprintf('request-%d-confirmation@%s', $this->request->id, config('helpstripe.inbound_domain')),
            references: array_filter([$openingMessageId]),
        );
    }
}
