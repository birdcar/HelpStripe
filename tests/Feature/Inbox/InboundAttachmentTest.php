<?php

use App\Jobs\ProcessInboundEmail;
use App\Models\Mailbox;
use App\Models\Note;
use App\Models\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\WebhookClient\Models\WebhookCall;

/*
 * Inbound attachment handling. Resend's webhook carries attachment metadata
 * only; the binaries live behind short-lived CDN download URLs in the
 * separate attachments listing. The job downloads each one independently —
 * an oversize or unfetchable file must never lose the whole email, it
 * becomes a private timeline note instead. These tests fake both the Resend
 * listing and the CDN binary so nothing touches the network.
 */

beforeEach(function () {
    Mail::fake();
    Storage::fake();
    Mailbox::factory()->create(['address' => 'billing@helpstripe.test']);
});

/**
 * Run inbound-attachment with a given attachment listing (lets each test
 * vary the reported size) and the binary download faked.
 *
 * @param  array<int, array<string, mixed>>  $listing
 */
function processAttachmentEmail(array $listing): void
{
    $fixture = resendFixture('inbound-attachment');
    $emailId = $fixture['webhook']['data']['email_id'];

    Http::fake([
        "api.resend.com/emails/receiving/{$emailId}/attachments" => Http::response(['object' => 'list', 'data' => $listing]),
        "api.resend.com/emails/receiving/{$emailId}" => Http::response($fixture['email']),
        '*' => Http::response('%PDF-1.4 fake binary'),
    ]);

    $webhookCall = WebhookCall::create([
        'name' => 'resend',
        'url' => 'http://localhost/webhooks/resend',
        'headers' => [],
        'payload' => $fixture['webhook'],
    ]);

    (new ProcessInboundEmail($webhookCall))->handle();
}

test('an in-bounds attachment is downloaded and attached to the opening note', function () {
    processAttachmentEmail([[
        'id' => 'att-1',
        'filename' => 'receipt.pdf',
        'size' => 2048,
        'download_url' => 'https://inbound-cdn.resend.com/att-1',
    ]]);

    $note = Request::query()->latest('id')->firstOrFail()->notes()->firstOrFail();

    expect($note->getMedia('attachments'))->toHaveCount(1)
        ->and($note->getFirstMedia('attachments')->file_name)->toBe('receipt.pdf');

    // No private skip note was added — the request has just the opening note.
    expect(Note::query()->where('is_private', true)->count())->toBe(0);
});

test('an attachment over the size cap is skipped and recorded as a private note', function () {
    config(['helpstripe.max_attachment_bytes' => 1024]);

    processAttachmentEmail([[
        'id' => 'att-big',
        'filename' => 'huge-export.pdf',
        'size' => 5_000_000, // way over the 1 KB cap configured above
        'download_url' => 'https://inbound-cdn.resend.com/att-big',
    ]]);

    $request = Request::query()->latest('id')->firstOrFail();

    // The opening note carries no media…
    expect($request->notes()->firstOrFail()->getMedia('attachments'))->toHaveCount(0);

    // …and a private note explains the skip, naming the file and the cap.
    $skipNote = Note::query()->where('is_private', true)->latest('id')->firstOrFail();
    expect($skipNote->body)->toContain('huge-export.pdf')
        ->and($skipNote->body)->toContain('1024')
        ->and($skipNote->is_private)->toBeTrue();
});

test('a single oversize attachment never drops the email itself', function () {
    config(['helpstripe.max_attachment_bytes' => 1024]);

    processAttachmentEmail([[
        'id' => 'att-big',
        'filename' => 'huge-export.pdf',
        'size' => 5_000_000,
        'download_url' => 'https://inbound-cdn.resend.com/att-big',
    ]]);

    // The request was still created with the customer's message intact.
    $request = Request::query()->latest('id')->firstOrFail();
    expect($request->subject)->toBe('Charged twice last month')
        ->and($request->notes()->where('is_private', false)->count())->toBe(1);
});
