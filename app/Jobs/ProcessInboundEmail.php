<?php

namespace App\Jobs;

use App\Actions\Requests\AddNote;
use App\Actions\Requests\ChangeStatus;
use App\Actions\Requests\CreateRequest;
use App\Enums\RequestSource;
use App\Enums\RequestStatus;
use App\Mail\NewRequestConfirmationMail;
use App\Models\Customer;
use App\Models\Mailbox;
use App\Models\Note;
use App\Models\Request;
use App\Support\Automation\MailRuleEvaluator;
use App\Support\Resend\InboundEmail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;
use Throwable;

/**
 * Turns a stored Resend webhook call into helpdesk data.
 *
 * Extends webhook-client's ProcessWebhookJob, so it's constructed with
 * the persisted WebhookCall and queued by the package's controller —
 * the HTTP request was already answered 200 by the time this runs.
 * Anything thrown here lands in failed_jobs (inspect with `php artisan
 * queue:failed`), with the original payload still on the webhook_calls
 * row for replay.
 *
 * Resend's `email.received` event carries metadata only; the body,
 * threading headers, and attachment binaries are fetched from the
 * Resend API here, then parsed once into the InboundEmail DTO.
 */
class ProcessInboundEmail extends ProcessWebhookJob
{
    private const string RESEND_API_URL = 'https://api.resend.com';

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $payload = $this->webhookCall->payload ?? [];

        // Only one event type is subscribed in the Resend dashboard, but
        // a guard documents the assumption and keeps stray events cheap.
        if (($payload['type'] ?? null) !== 'email.received') {
            return;
        }

        $emailId = $payload['data']['email_id'] ?? null;

        if (! is_string($emailId) || $emailId === '') {
            throw new RuntimeException('Resend webhook payload is missing data.email_id.');
        }

        $email = InboundEmail::fromResend($payload, $this->fetchEmailContent($emailId));

        // Idempotency: Resend redelivers when our endpoint answers slowly
        // or 5xxes. The Message-ID landed on a note the first time this
        // email was processed — seeing it again means redelivery, not a
        // new message.
        if ($email->messageId !== null && Note::query()->where('message_id', $email->messageId)->exists()) {
            return;
        }

        $mailbox = Mailbox::query()->where('address', $email->primaryTo())->first()
            ?? Mailbox::query()->orderBy('id')->first();

        if ($mailbox === null) {
            throw new RuntimeException('No mailbox configured to receive inbound email.');
        }

        $customer = $this->resolveCustomer($email, $mailbox->team_id);

        [$attachments, $skipped] = $this->downloadAttachments($email);

        $request = Request::findForInbound($email, $mailbox);

        if ($request !== null) {
            app(AddNote::class)->handle(
                $request,
                $customer,
                $email->body,
                isPrivate: false,
                source: RequestSource::Email,
                messageId: $email->messageId,
                attachments: $attachments,
            );

            // A customer replying to a Resolved/Closed request means it
            // isn't resolved for THEM — reopen it so it re-enters the
            // queue. ChangeStatus owns the resolved_at bookkeeping.
            if (in_array($request->status, [RequestStatus::Resolved, RequestStatus::Closed], true)) {
                app(ChangeStatus::class)->handle($request, RequestStatus::Active);
            }
        } else {
            // Mail Rules run only for *new* requests, before creation — replies
            // (the matched branch above) bypass them, mirroring HelpSpot. The
            // rules accumulate category/assignee/urgency overrides that fold
            // into the create payload, so the request is correct from birth
            // rather than created-then-edited. A rule-set category wins over the
            // mailbox's default (the spread below applies overrides last).
            $overrides = $this->applyMailRules($email, $mailbox->team_id);

            $request = app(CreateRequest::class)->handle(
                $customer,
                $email->subject,
                $email->body,
                RequestSource::Email,
                [
                    'mailbox_id' => $mailbox->id,
                    'category_id' => $mailbox->category_id,
                    'message_id' => $email->messageId,
                    ...$overrides,
                ],
            );

            // CreateRequest made the opening note inside its transaction;
            // attachments hang off that note, same as on a reply.
            $openingNote = $request->notes()->orderBy('id')->first();

            foreach ($attachments as $attachment) {
                $openingNote?->addMedia($attachment['path'])
                    ->usingFileName($attachment['name'])
                    ->toMediaCollection('attachments');
            }

            // Tell the customer we have it: request number for the subject
            // token, access key for the Phase 4 portal.
            Mail::to($customer->email)->send(new NewRequestConfirmationMail($request));
        }

        // Anything that couldn't be imported is recorded on the timeline
        // (privately) instead of vanishing — staff can ask the customer
        // to re-send. Authored by the customer: it describes THEIR email,
        // and notes require an author (no system user exists).
        foreach ($skipped as $skippedMessage) {
            app(AddNote::class)->handle(
                $request,
                $customer,
                $skippedMessage,
                isPrivate: true,
                source: RequestSource::Email,
            );
        }
    }

    /**
     * Compute the Mail Rule overrides for a brand-new request from this email.
     *
     * Delegates to MailRuleEvaluator (the Phase 6 engine), which runs the
     * team's active mail-layer rules in position order and returns the
     * category/assignee/urgency overrides the matched rules want. Returns an
     * empty array when no rules match — the no-rules path is byte-identical to
     * the pre-Phase-6 pipeline, so the existing Inbox tests stay green.
     *
     * @return array{category_id?: int, assigned_to?: int, is_urgent?: bool}
     */
    protected function applyMailRules(InboundEmail $email, int $teamId): array
    {
        return app(MailRuleEvaluator::class)->overridesFor($email, $teamId);
    }

    /**
     * Fetch the full email (body, headers) from the Resend API.
     *
     * @return array<string, mixed>
     */
    protected function fetchEmailContent(string $emailId): array
    {
        return Http::withToken((string) config('services.resend.key'))
            ->acceptJson()
            ->get(self::RESEND_API_URL."/emails/receiving/{$emailId}")
            ->throw()
            ->json();
    }

    /**
     * Find the customer by email (case-insensitively) or create them.
     *
     * Email addresses are compared lowercased — `Pat@Example.com` and
     * `pat@example.com` are the same person, and creating a duplicate
     * customer would split their request history.
     */
    protected function resolveCustomer(InboundEmail $email, int $teamId): Customer
    {
        $normalizedEmail = Str::lower($email->fromEmail);

        $existing = Customer::query()
            ->where('team_id', $teamId)
            ->whereRaw('lower(email) = ?', [$normalizedEmail])
            ->first();

        return $existing ?? Customer::create([
            'team_id' => $teamId,
            'name' => $email->fromName ?? Str::before($email->fromEmail, '@'),
            'email' => $normalizedEmail,
        ]);
    }

    /**
     * Download this email's attachments to temp files.
     *
     * Resend hosts attachment binaries behind short-lived download URLs
     * (listed per email); the webhook only carries their metadata. Each
     * attachment fails independently: an oversize or unfetchable file
     * becomes a private timeline note, never a lost email.
     *
     * @return array{0: array<int, array{path: string, name: string}>, 1: list<string>}
     */
    protected function downloadAttachments(InboundEmail $email): array
    {
        if ($email->attachments === []) {
            return [[], []];
        }

        $files = [];
        $skipped = [];
        $maxBytes = (int) config('helpstripe.max_attachment_bytes');

        $listed = Http::withToken((string) config('services.resend.key'))
            ->acceptJson()
            ->get(self::RESEND_API_URL."/emails/receiving/{$email->emailId}/attachments")
            ->throw()
            ->json('data', []);

        foreach ($listed as $attachment) {
            $filename = (string) ($attachment['filename'] ?? 'attachment');

            if ((int) ($attachment['size'] ?? 0) > $maxBytes) {
                $skipped[] = sprintf(
                    'Attachment "%s" was not imported: it exceeds the %d byte size cap.',
                    $filename,
                    $maxBytes,
                );

                continue;
            }

            try {
                $binary = Http::get((string) $attachment['download_url'])->throw()->body();

                $path = tempnam(sys_get_temp_dir(), 'helpstripe-inbound-');

                if ($path === false) {
                    throw new RuntimeException('Could not allocate a temp file for an inbound attachment.');
                }

                file_put_contents($path, $binary);

                $files[] = ['path' => $path, 'name' => $filename];
            } catch (Throwable) {
                $skipped[] = sprintf('Attachment "%s" could not be imported.', $filename);
            }
        }

        return [$files, $skipped];
    }
}
