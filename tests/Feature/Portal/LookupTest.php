<?php

use App\Models\Customer;
use App\Models\Note;
use App\Models\Request;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
 * The two ways a customer reaches a request's status page — the signed
 * email link and a manual email + access-key lookup — plus the security
 * properties: generic failure messages, signature verification, and the
 * hard rule that private notes never reach the customer.
 */

/**
 * A request owned by a fresh installation team, with a known customer
 * email and access key.
 *
 * @return array{0: Request, 1: Customer}
 */
function portalRequest(string $email = 'dana@example.com', string $accessKey = 'KEY1234567AB'): array
{
    $team = Team::factory()->create();
    $customer = Customer::factory()->create(['team_id' => $team->id, 'email' => $email]);
    $request = Request::factory()->create([
        'team_id' => $team->id,
        'customer_id' => $customer->id,
        'access_key' => $accessKey,
        'subject' => 'My printer is on fire',
    ]);

    return [$request, $customer];
}

test('a valid signed link opens the status page', function () {
    [$request] = portalRequest();

    $url = URL::signedRoute('portal.status', ['request' => $request->id]);

    $this->get($url)
        ->assertOk()
        ->assertSee('My printer is on fire');
});

test('a tampered signed link is rejected with 403', function () {
    [$request] = portalRequest();

    $url = URL::signedRoute('portal.status', ['request' => $request->id]);

    $this->get($url.'tampered')->assertStatus(403);
});

test('the unsigned status route 403s without a verified session', function () {
    [$request] = portalRequest();

    $this->get(route('portal.status.show', ['request' => $request->id]))
        ->assertStatus(403);
});

test('a correct email and access key verifies and redirects to the status page', function () {
    [$request] = portalRequest(email: 'dana@example.com', accessKey: 'KEY1234567AB');

    Livewire::test('pages::portal.lookup')
        ->set('email', 'dana@example.com')
        ->set('accessKey', 'KEY1234567AB')
        ->call('lookup')
        ->assertHasNoErrors()
        ->assertRedirect(route('portal.status.show', ['request' => $request->id]));

    // The grant persisted in the session lets the unsigned route through.
    $this->get(route('portal.status.show', ['request' => $request->id]))->assertOk();
});

test('email matching is case-insensitive but the access key is exact', function () {
    [$request] = portalRequest(email: 'dana@example.com', accessKey: 'KEY1234567AB');

    Livewire::test('pages::portal.lookup')
        ->set('email', 'DANA@Example.com')
        ->set('accessKey', 'KEY1234567AB')
        ->call('lookup')
        ->assertHasNoErrors()
        ->assertRedirect(route('portal.status.show', ['request' => $request->id]));
});

test('a wrong access key shows a generic error and does not verify', function () {
    [$request] = portalRequest(email: 'dana@example.com', accessKey: 'KEY1234567AB');

    Livewire::test('pages::portal.lookup')
        ->set('email', 'dana@example.com')
        ->set('accessKey', 'WRONGKEY0000')
        ->call('lookup')
        ->assertHasErrors('lookup')
        ->assertNoRedirect()
        ->assertSee("couldn't find a matching request");

    $this->get(route('portal.status.show', ['request' => $request->id]))->assertStatus(403);
});

test('an unknown email shows the same generic error (no enumeration)', function () {
    portalRequest(email: 'dana@example.com', accessKey: 'KEY1234567AB');

    Livewire::test('pages::portal.lookup')
        ->set('email', 'nobody@example.com')
        ->set('accessKey', 'KEY1234567AB')
        ->call('lookup')
        ->assertHasErrors('lookup')
        ->assertSee("couldn't find a matching request");
});

test('the access key for a different request does not unlock this one', function () {
    [$first] = portalRequest(email: 'dana@example.com', accessKey: 'KEY1234567AB');
    $second = Request::factory()->create([
        'team_id' => $first->team_id,
        'customer_id' => $first->customer_id,
        'access_key' => 'OTHERKEY9999',
    ]);

    // Dana's email is right, but she's pasting the SECOND request's key
    // against an implicit attempt to view the FIRST — the lookup resolves
    // to whichever request the key belongs to, never a mismatch.
    Livewire::test('pages::portal.lookup')
        ->set('email', 'dana@example.com')
        ->set('accessKey', 'OTHERKEY9999')
        ->call('lookup')
        ->assertRedirect(route('portal.status.show', ['request' => $second->id]));

    // The first request's session was never granted.
    $this->get(route('portal.status.show', ['request' => $first->id]))->assertStatus(403);
});

test('private staff notes never appear on the customer status page', function () {
    [$request] = portalRequest();
    $staff = User::factory()->create();

    Note::factory()->create([
        'request_id' => $request->id,
        'user_id' => $staff->id,
        'customer_id' => null,
        'is_private' => false,
        'body' => 'PUBLIC_REPLY_VISIBLE_TO_CUSTOMER',
    ]);

    Note::factory()->create([
        'request_id' => $request->id,
        'user_id' => $staff->id,
        'customer_id' => null,
        'is_private' => true,
        'body' => 'PRIVATE_INTERNAL_NOTE_SECRET',
    ]);

    $url = URL::signedRoute('portal.status', ['request' => $request->id]);

    $this->get($url)
        ->assertOk()
        ->assertSee('PUBLIC_REPLY_VISIBLE_TO_CUSTOMER')
        ->assertDontSee('PRIVATE_INTERNAL_NOTE_SECRET');
});
