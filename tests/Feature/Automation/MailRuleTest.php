<?php

use App\Enums\ConditionField;
use App\Enums\ConditionOperator;
use App\Enums\RuleAction;
use App\Jobs\ProcessInboundEmail;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\Mailbox;
use App\Models\Request;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\WebhookClient\Models\WebhookCall;

/*
 * Mail Rules in the inbound pipeline: active mail-layer rules run against the
 * InboundEmail before a NEW request is created, folding category/assignee/
 * urgency overrides into the create payload (one create, correct from birth).
 * Replies bypass rules entirely (HelpSpot behavior).
 */

beforeEach(function () {
    Mail::fake();
});

/**
 * Persist a WebhookCall for the inbound-new fixture and fake the Resend fetch.
 * Mirrors InboundMatchingTest's helper so these tests exercise the real job.
 */
function processNewInbound(): ProcessInboundEmail
{
    $fixture = resendFixture('inbound-new');
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

test('a matching mail rule routes a new request to its category', function () {
    $mailbox = Mailbox::factory()->create(['address' => 'support@helpstripe.test', 'category_id' => null]);
    $billing = Category::factory()->create(['team_id' => $mailbox->team_id, 'name' => 'Billing']);

    // Subject of the fixture is "Can't sign in to the dashboard".
    AutomationRule::factory()->mail()->create([
        'team_id' => $mailbox->team_id,
        'name' => 'Sign-in routing',
        'conditions' => [
            ['field' => ConditionField::Subject->value, 'operator' => ConditionOperator::Contains->value, 'value' => 'sign in'],
        ],
        'actions' => [
            ['action' => RuleAction::SetCategory->value, 'value' => $billing->id],
        ],
    ]);

    processNewInbound()->handle();

    $request = Request::query()->latest('id')->firstOrFail();

    expect($request->category_id)->toBe($billing->id);
});

test('two matching rules apply in position order — later wins on the same field', function () {
    $mailbox = Mailbox::factory()->create(['address' => 'support@helpstripe.test', 'category_id' => null]);
    $first = Category::factory()->create(['team_id' => $mailbox->team_id, 'name' => 'First']);
    $second = Category::factory()->create(['team_id' => $mailbox->team_id, 'name' => 'Second']);

    AutomationRule::factory()->mail()->create([
        'team_id' => $mailbox->team_id,
        'name' => 'First rule',
        'position' => 1,
        'conditions' => [],
        'actions' => [['action' => RuleAction::SetCategory->value, 'value' => $first->id]],
    ]);
    AutomationRule::factory()->mail()->create([
        'team_id' => $mailbox->team_id,
        'name' => 'Second rule',
        'position' => 2,
        'conditions' => [],
        'actions' => [['action' => RuleAction::SetCategory->value, 'value' => $second->id]],
    ]);

    processNewInbound()->handle();

    expect(Request::query()->latest('id')->firstOrFail()->category_id)->toBe($second->id);
});

test('an inactive mail rule is ignored', function () {
    $mailbox = Mailbox::factory()->create(['address' => 'support@helpstripe.test', 'category_id' => null]);
    $billing = Category::factory()->create(['team_id' => $mailbox->team_id]);

    AutomationRule::factory()->mail()->inactive()->create([
        'team_id' => $mailbox->team_id,
        'conditions' => [],
        'actions' => [['action' => RuleAction::SetCategory->value, 'value' => $billing->id]],
    ]);

    processNewInbound()->handle();

    expect(Request::query()->latest('id')->firstOrFail()->category_id)->toBeNull();
});

test('a mail rule can set urgent and assign on creation', function () {
    $mailbox = Mailbox::factory()->create(['address' => 'support@helpstripe.test', 'category_id' => null]);
    $agent = User::factory()->create();

    AutomationRule::factory()->mail()->create([
        'team_id' => $mailbox->team_id,
        'conditions' => [],
        'actions' => [
            ['action' => RuleAction::SetUrgent->value, 'value' => true],
            ['action' => RuleAction::AssignTo->value, 'value' => $agent->id],
        ],
    ]);

    processNewInbound()->handle();

    $request = Request::query()->latest('id')->firstOrFail();

    expect($request->is_urgent)->toBeTrue()
        ->and($request->assigned_to)->toBe($agent->id);
});

test('a non-matching mail rule leaves the request on the mailbox default', function () {
    $mailboxCategory = Category::factory()->create(['name' => 'Mailbox default']);
    $mailbox = Mailbox::factory()->create([
        'address' => 'support@helpstripe.test',
        'team_id' => $mailboxCategory->team_id,
        'category_id' => $mailboxCategory->id,
    ]);
    $other = Category::factory()->create(['team_id' => $mailbox->team_id]);

    AutomationRule::factory()->mail()->create([
        'team_id' => $mailbox->team_id,
        'conditions' => [
            ['field' => ConditionField::Subject->value, 'operator' => ConditionOperator::Contains->value, 'value' => 'refund'],
        ],
        'actions' => [['action' => RuleAction::SetCategory->value, 'value' => $other->id]],
    ]);

    processNewInbound()->handle();

    expect(Request::query()->latest('id')->firstOrFail()->category_id)->toBe($mailboxCategory->id);
});

test('a reply bypasses mail rules', function () {
    $mailbox = Mailbox::factory()->create(['address' => 'support@helpstripe.test', 'category_id' => null]);
    $billing = Category::factory()->create(['team_id' => $mailbox->team_id]);

    // Open the original request (no rules yet for it — created cleanly).
    processNewInbound()->handle();
    $original = Request::query()->latest('id')->firstOrFail();

    // Now add a mail rule that WOULD set a category, then send a reply.
    AutomationRule::factory()->mail()->create([
        'team_id' => $mailbox->team_id,
        'conditions' => [],
        'actions' => [['action' => RuleAction::SetCategory->value, 'value' => $billing->id]],
    ]);

    $fixture = resendFixture('inbound-reply');
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
    (new ProcessInboundEmail($webhookCall))->handle();

    // Still one request, and the rule never touched its category.
    expect(Request::query()->count())->toBe(1)
        ->and($original->refresh()->category_id)->toBeNull();
});
