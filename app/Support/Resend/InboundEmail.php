<?php

namespace App\Support\Resend;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One inbound email, parsed once.
 *
 * Resend's `email.received` webhook carries metadata only; the body and
 * threading headers come from a second API call (the job fetches them).
 * This DTO is the single place both payloads are interpreted — if Resend
 * changes a field name, this file is the only parse point to update (a
 * named mitigation in the spec's failure-mode table).
 *
 * Message-IDs are normalized here: on the wire they're wrapped in angle
 * brackets (`<id@host>`), but we store and compare them bare, so every
 * consumer works with one canonical form.
 */
final class InboundEmail
{
    /**
     * @param  list<string>  $to  recipient addresses, lowercased
     * @param  list<string>  $references  bare message-ids from the References header, oldest first
     * @param  array<int, array{id: string, filename: string, content_type: string}>  $attachments
     */
    private function __construct(
        public readonly string $emailId,
        public readonly string $fromEmail,
        public readonly ?string $fromName,
        public readonly array $to,
        public readonly string $subject,
        public readonly string $body,
        public readonly ?string $messageId,
        public readonly ?string $inReplyTo,
        public readonly array $references,
        public readonly array $attachments,
    ) {}

    /**
     * Build the DTO from the webhook payload plus the retrieved email.
     *
     * @param  array<string, mixed>  $webhookPayload  the stored `email.received` payload
     * @param  array<string, mixed>  $emailContent  the `GET /emails/receiving/{id}` response
     */
    public static function fromResend(array $webhookPayload, array $emailContent): self
    {
        $data = $webhookPayload['data'] ?? [];

        // Missing sender or subject means the payload isn't something we
        // can file as a request — fail loudly so the job lands in
        // failed_jobs where a human can inspect the stored webhook call.
        $rawFrom = $data['from'] ?? $emailContent['from'] ?? null;
        $subject = $data['subject'] ?? $emailContent['subject'] ?? null;

        if (! is_string($rawFrom) || trim($rawFrom) === '') {
            throw new InvalidArgumentException('Inbound email payload has no "from" address.');
        }

        if (! is_string($subject)) {
            throw new InvalidArgumentException('Inbound email payload has no subject.');
        }

        [$fromName, $fromEmail] = self::parseAddress($rawFrom);

        $headers = self::lowercaseKeys($emailContent['headers'] ?? []);

        // Prefer the text part; fall back to tag-stripped HTML. Real
        // sanitization (CSS, tracking pixels, quoted-reply trimming) is
        // out of scope for a teaching repo — named in the tour doc.
        $text = $emailContent['text'] ?? null;
        $html = $emailContent['html'] ?? null;
        $body = is_string($text) && trim($text) !== ''
            ? trim($text)
            : trim(strip_tags(is_string($html) ? $html : ''));

        return new self(
            emailId: $data['email_id'] ?? $emailContent['id'] ?? '',
            fromEmail: $fromEmail,
            fromName: $fromName,
            to: array_values(array_map(
                fn (string $address) => Str::lower(self::parseAddress($address)[1]),
                $data['to'] ?? $emailContent['to'] ?? [],
            )),
            subject: $subject,
            body: $body,
            messageId: self::normalizeMessageId($data['message_id'] ?? $emailContent['message_id'] ?? null),
            inReplyTo: self::normalizeMessageId($headers['in-reply-to'] ?? null),
            references: self::parseReferences($headers['references'] ?? null),
            attachments: array_values(array_map(fn (array $attachment) => [
                'id' => (string) ($attachment['id'] ?? ''),
                'filename' => (string) ($attachment['filename'] ?? 'attachment'),
                'content_type' => (string) ($attachment['content_type'] ?? 'application/octet-stream'),
            ], $data['attachments'] ?? $emailContent['attachments'] ?? [])),
        );
    }

    /**
     * The first recipient address — the mailbox the mail was sent to.
     */
    public function primaryTo(): ?string
    {
        return $this->to[0] ?? null;
    }

    /**
     * Every message-id this email claims to descend from, for matching.
     *
     * @return list<string>
     */
    public function referencedMessageIds(): array
    {
        return array_values(array_unique(array_filter([
            ...$this->references,
            $this->inReplyTo,
        ])));
    }

    /**
     * Split "Display Name <user@host>" into [name, email].
     *
     * @return array{0: string|null, 1: string}
     */
    private static function parseAddress(string $address): array
    {
        if (preg_match('/^(.*)<([^>]+)>\s*$/', $address, $matches) === 1) {
            $name = trim($matches[1], " \t\"'");

            return [$name === '' ? null : $name, trim($matches[2])];
        }

        return [null, trim($address)];
    }

    /**
     * Strip the RFC 5322 angle brackets from a message-id.
     */
    private static function normalizeMessageId(?string $messageId): ?string
    {
        if ($messageId === null || trim($messageId) === '') {
            return null;
        }

        return trim($messageId, " \t<>");
    }

    /**
     * Parse a References header: whitespace-separated `<id>` tokens.
     *
     * @return list<string>
     */
    private static function parseReferences(?string $references): array
    {
        if ($references === null || trim($references) === '') {
            return [];
        }

        preg_match_all('/<([^>]+)>/', $references, $matches);

        return $matches[1];
    }

    /**
     * Lowercase header names so lookups are case-insensitive (mail
     * headers are case-insensitive by spec; senders vary freely).
     *
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    private static function lowercaseKeys(array $headers): array
    {
        return array_combine(
            array_map(fn (string $key) => Str::lower($key), array_keys($headers)),
            array_values($headers),
        );
    }
}
