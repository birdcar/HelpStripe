<?php

use App\Jobs\ProcessInboundEmail;
use App\Models\Customer;
use App\Models\Mailbox;
use App\Models\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\WebhookClient\Models\WebhookCall;

/*
 * Signature material: the svix scheme HMACs "{id}.{timestamp}.{raw body}"
 * with the base64-decoded part of the whsec_ secret. These helpers build
 * real signatures so the validator is exercised exactly as Resend would.
 */

function inboxTestSecret(): string
{
    return 'whsec_'.base64_encode('helpstripe-test-signing-secret');
}

/**
 * @return array<string, string>
 */
function svixSignedHeaders(string $body, ?string $timestamp = null, ?string $secret = null): array
{
    $secret ??= inboxTestSecret();
    $timestamp ??= (string) now()->getTimestamp();
    $id = 'msg_'.substr(md5($body.$timestamp), 0, 10);

    $key = (string) base64_decode(Str::after($secret, 'whsec_'), true);
    $signature = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));

    return [
        'svix-id' => $id,
        'svix-timestamp' => $timestamp,
        'svix-signature' => "v1,{$signature}",
    ];
}

beforeEach(function () {
    config(['webhook-client.configs.0.signing_secret' => inboxTestSecret()]);
});

test('a validly signed webhook creates a request end-to-end from the fixture', function () {
    Mail::fake();

    $mailbox = Mailbox::factory()->create(['address' => 'support@helpstripe.test']);
    $fixture = resendFixture('inbound-new');

    Http::fake([
        "api.resend.com/emails/receiving/{$fixture['webhook']['data']['email_id']}" => Http::response($fixture['email']),
    ]);

    $body = json_encode($fixture['webhook']);

    $this->postJson('/webhooks/resend', $fixture['webhook'], svixSignedHeaders($body))
        ->assertOk();

    expect(WebhookCall::query()->count())->toBe(1);

    $request = Request::query()->latest('id')->firstOrFail();

    expect($request->subject)->toBe("Can't sign in to the dashboard")
        ->and($request->mailbox_id)->toBe($mailbox->id)
        ->and($request->source->value)->toBe('email')
        ->and($request->notes()->count())->toBe(1)
        ->and($request->notes()->first()->message_id)->toBe('demo-original@customer.example')
        ->and($request->notes()->first()->body)->toBe('Hi — my password stopped working this morning. Can you help?');

    expect(Customer::query()->where('email', 'dana@customer.example')->exists())->toBeTrue();
});

test('a webhook with an invalid signature is rejected with 401 and nothing is stored', function () {
    $fixture = resendFixture('inbound-new');
    $body = json_encode($fixture['webhook']);
    $headers = svixSignedHeaders($body, secret: 'whsec_'.base64_encode('the-wrong-secret'));

    $this->postJson('/webhooks/resend', $fixture['webhook'], $headers)
        ->assertUnauthorized();

    expect(WebhookCall::query()->count())->toBe(0)
        ->and(Request::query()->count())->toBe(0);
});

test('a webhook missing the svix headers is rejected with 401', function () {
    $fixture = resendFixture('inbound-new');

    $this->postJson('/webhooks/resend', $fixture['webhook'])
        ->assertUnauthorized();

    expect(WebhookCall::query()->count())->toBe(0);
});

test('a webhook with a stale timestamp is rejected even when correctly signed', function () {
    $fixture = resendFixture('inbound-new');
    $body = json_encode($fixture['webhook']);
    $staleTimestamp = (string) now()->subHour()->getTimestamp();

    $this->postJson('/webhooks/resend', $fixture['webhook'], svixSignedHeaders($body, timestamp: $staleTimestamp))
        ->assertUnauthorized();

    expect(WebhookCall::query()->count())->toBe(0);
});

test('the webhook answers 200 before processing runs (store, then process)', function () {
    // Queue::fake() severs storage from processing — exactly the
    // production shape, where a malformed payload fails in the worker
    // AFTER Resend already got its 200 (so it never retries pointlessly).
    Queue::fake();

    $fixture = resendFixture('inbound-new');
    $body = json_encode($fixture['webhook']);

    $this->postJson('/webhooks/resend', $fixture['webhook'], svixSignedHeaders($body))
        ->assertOk();

    expect(WebhookCall::query()->count())->toBe(1);
    Queue::assertPushed(ProcessInboundEmail::class);
});

test('a malformed payload fails the job loudly instead of being silently dropped', function () {
    $webhookCall = WebhookCall::create([
        'name' => 'resend',
        'url' => 'http://localhost/webhooks/resend',
        'headers' => [],
        'payload' => [
            'type' => 'email.received',
            'data' => [
                'email_id' => 'broken-no-sender',
                'to' => ['support@helpstripe.test'],
                'subject' => 'No sender on this one',
            ],
        ],
    ]);

    Http::fake([
        'api.resend.com/emails/receiving/broken-no-sender' => Http::response(['object' => 'email', 'text' => 'Body']),
    ]);

    expect(fn () => (new ProcessInboundEmail($webhookCall))->handle())
        ->toThrow(InvalidArgumentException::class);
});
