<?php

use App\Enums\RequestStatus;
use App\Jobs\ProcessInboundEmail;
use App\Models\Customer;
use App\Models\Mailbox;
use App\Models\Note;
use App\Models\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\WebhookClient\Models\WebhookCall;

/*
 * The matching matrix: every way an inbound email finds (or doesn't find)
 * an existing request. These tests skip the HTTP layer (InboundWebhookTest
 * covers signing) and drive ProcessInboundEmail directly off a stored
 * WebhookCall — the same object the package would queue. The Resend content
 * API is faked from the recorded fixtures so nothing touches the network.
 */

beforeEach(function () {
    Mail::fake();
});

/**
 * Persist a WebhookCall for a fixture and fake the Resend content fetch.
 *
 * Returns the call so the test can run the job: `processInbound(...)->handle()`.
 */
function processInbound(string $fixtureName): ProcessInboundEmail
{
    $fixture = resendFixture($fixtureName);
    $emailId = $fixture['webhook']['data']['email_id'];

    Http::fake([
        "api.resend.com/emails/receiving/{$emailId}/attachments" => Http::response($fixture['attachments']),
        "api.resend.com/emails/receiving/{$emailId}" => Http::response($fixture['email']),
    ]);

    $webhookCall = WebhookCall::create([
        'name' => 'resend',
        'url' => 'http://localhost/webhooks/resend',
        'headers' => [],
        'payload' => $fixture['webhook'],
    ]);

    return new ProcessInboundEmail($webhookCall);
}

test('a fresh email with no threading opens a new request on the matching mailbox', function () {
    $mailbox = Mailbox::factory()->create(['address' => 'support@helpstripe.test', 'category_id' => null]);

    processInbound('inbound-new')->handle();

    $request = Request::query()->latest('id')->firstOrFail();

    expect(Request::query()->count())->toBe(1)
        ->and($request->mailbox_id)->toBe($mailbox->id)
        ->and($request->subject)->toBe("Can't sign in to the dashboard")
        ->and($request->status)->toBe(RequestStatus::Active)
        ->and($request->source->value)->toBe('email');
});

test('a reply whose headers reference a stored message id threads onto the existing request', function () {
    $mailbox = Mailbox::factory()->create(['address' => 'support@helpstripe.test']);

    // Seed the original via the same pipeline so notes.message_id is real.
    processInbound('inbound-new')->handle();
    $original = Request::query()->latest('id')->firstOrFail();

    processInbound('inbound-reply')->handle();

    expect(Request::query()->count())->toBe(1)
        ->and($original->refresh()->notes()->count())->toBe(2);

    $reply = $original->notes()->latest('id')->first();
    expect($reply->body)->toBe('Forgot to say — I already tried resetting it twice.')
        ->and($reply->message_id)->toBe('demo-reply@customer.example')
        ->and($reply->is_private)->toBeFalse();
});

test('a reply whose headers match nothing falls back to the subject token', function () {
    $mailbox = Mailbox::factory()->create(['address' => 'support@helpstripe.test']);
    $customer = Customer::factory()->create(['team_id' => $mailbox->team_id]);
    $existing = Request::factory()->create([
        'team_id' => $mailbox->team_id,
        'customer_id' => $customer->id,
        'mailbox_id' => $mailbox->id,
    ]);

    // A reply carrying the [#id] token but no matching In-Reply-To header.
    $fixture = resendFixture('inbound-new');
    $fixture['webhook']['data']['email_id'] = 'token-only-reply';
    $fixture['webhook']['data']['message_id'] = '<token-reply@customer.example>';
    $fixture['webhook']['data']['subject'] = "Re: Anything at all [#{$existing->id}]";
    $fixture['email']['id'] = 'token-only-reply';
    $fixture['email']['message_id'] = '<token-reply@customer.example>';
    $fixture['email']['subject'] = "Re: Anything at all [#{$existing->id}]";
    $fixture['email']['headers'] = ['from' => $fixture['email']['from']];

    Http::fake([
        'api.resend.com/emails/receiving/token-only-reply' => Http::response($fixture['email']),
    ]);

    $webhookCall = WebhookCall::create([
        'name' => 'resend',
        'url' => 'http://localhost/webhooks/resend',
        'headers' => [],
        'payload' => $fixture['webhook'],
    ]);

    (new ProcessInboundEmail($webhookCall))->handle();

    expect(Request::query()->count())->toBe(1)
        ->and($existing->refresh()->notes()->count())->toBe(1);
});

test('the subject token is scoped to the receiving mailbox team so it cannot cross installations', function () {
    $mailbox = Mailbox::factory()->create(['address' => 'support@helpstripe.test']);
    // A request that belongs to a DIFFERENT team — its id appears in the token.
    $otherTeamRequest = Request::factory()->create();

    $fixture = resendFixture('inbound-new');
    $fixture['webhook']['data']['email_id'] = 'cross-team-token';
    $fixture['webhook']['data']['message_id'] = '<cross-team@customer.example>';
    $fixture['webhook']['data']['subject'] = "Re: Something [#{$otherTeamRequest->id}]";
    $fixture['email']['id'] = 'cross-team-token';
    $fixture['email']['message_id'] = '<cross-team@customer.example>';
    $fixture['email']['subject'] = "Re: Something [#{$otherTeamRequest->id}]";
    $fixture['email']['headers'] = ['from' => $fixture['email']['from']];

    Http::fake([
        'api.resend.com/emails/receiving/cross-team-token' => Http::response($fixture['email']),
    ]);

    $webhookCall = WebhookCall::create([
        'name' => 'resend',
        'url' => 'http://localhost/webhooks/resend',
        'headers' => [],
        'payload' => $fixture['webhook'],
    ]);

    (new ProcessInboundEmail($webhookCall))->handle();

    // The token didn't land on the other team's request (it stays note-free);
    // a brand-new request opened on the receiving mailbox's team instead.
    expect($otherTeamRequest->refresh()->notes()->count())->toBe(0)
        ->and(Request::query()->where('team_id', $mailbox->team_id)->count())->toBe(1);
});

test('an email to an unknown address falls back to the first mailbox', function () {
    $first = Mailbox::factory()->create(['address' => 'support@helpstripe.test']);
    Mailbox::factory()->create(['address' => 'billing@helpstripe.test', 'team_id' => $first->team_id]);

    // inbound-new is addressed to support@; rewrite it to an unrouted address.
    $fixture = resendFixture('inbound-new');
    $fixture['webhook']['data']['email_id'] = 'unknown-to';
    $fixture['webhook']['data']['message_id'] = '<unknown-to@customer.example>';
    $fixture['webhook']['data']['to'] = ['nobody@helpstripe.test'];
    $fixture['email']['id'] = 'unknown-to';
    $fixture['email']['message_id'] = '<unknown-to@customer.example>';
    $fixture['email']['to'] = ['nobody@helpstripe.test'];

    Http::fake([
        'api.resend.com/emails/receiving/unknown-to' => Http::response($fixture['email']),
    ]);

    $webhookCall = WebhookCall::create([
        'name' => 'resend',
        'url' => 'http://localhost/webhooks/resend',
        'headers' => [],
        'payload' => $fixture['webhook'],
    ]);

    (new ProcessInboundEmail($webhookCall))->handle();

    expect(Request::query()->latest('id')->firstOrFail()->mailbox_id)->toBe($first->id);
});

test('a customer reply to a resolved request reopens it', function () {
    $mailbox = Mailbox::factory()->create(['address' => 'support@helpstripe.test']);

    processInbound('inbound-new')->handle();
    $request = Request::query()->latest('id')->firstOrFail();
    $request->update(['status' => RequestStatus::Resolved, 'resolved_at' => now()]);

    processInbound('inbound-reply')->handle();

    expect($request->refresh()->status)->toBe(RequestStatus::Active)
        ->and($request->resolved_at)->toBeNull();
});

test('a redelivered webhook is idempotent — the same message id yields a single note', function () {
    Mailbox::factory()->create(['address' => 'support@helpstripe.test']);

    processInbound('inbound-new')->handle();
    // Resend redelivers the identical event after a slow/5xx response.
    processInbound('inbound-new')->handle();

    expect(Request::query()->count())->toBe(1)
        ->and(Note::query()->where('message_id', 'demo-original@customer.example')->count())->toBe(1);
});

test('a customer emailing twice differing only by case reuses the same customer record', function () {
    $mailbox = Mailbox::factory()->create(['address' => 'support@helpstripe.test']);
    // A pre-existing customer with a lowercased address.
    Customer::factory()->create(['team_id' => $mailbox->team_id, 'email' => 'dana@customer.example']);

    // inbound-new is from "Dana Customer <dana@customer.example>"; send it
    // with an uppercased local part instead.
    $fixture = resendFixture('inbound-new');
    $fixture['webhook']['data']['from'] = 'Dana Customer <Dana@Customer.Example>';
    $fixture['email']['from'] = 'Dana Customer <Dana@Customer.Example>';

    Http::fake([
        'api.resend.com/emails/receiving/'.$fixture['webhook']['data']['email_id'] => Http::response($fixture['email']),
    ]);

    $webhookCall = WebhookCall::create([
        'name' => 'resend',
        'url' => 'http://localhost/webhooks/resend',
        'headers' => [],
        'payload' => $fixture['webhook'],
    ]);

    (new ProcessInboundEmail($webhookCall))->handle();

    expect(Customer::query()->where('team_id', $mailbox->team_id)->count())->toBe(1);
});
