<?php

use App\Enums\RequestSource;
use App\Enums\RequestStatus;
use App\Models\Customer;
use App\Models\Request;
use App\Models\Team;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;

/*
 * Customer replies from the status page. A reply routes through the same
 * AddNote action staff and the email pipeline use (authored by the Customer,
 * RequestSource::Portal) and reopens a Resolved/Closed request — identical
 * to a customer's email reply.
 */

/**
 * A request the visitor is already verified to view (session flag set), in
 * a chosen status.
 *
 * @return array{0: Request, 1: Customer}
 */
function verifiedPortalRequest(RequestStatus $status = RequestStatus::Active): array
{
    $team = Team::factory()->create();
    $customer = Customer::factory()->create(['team_id' => $team->id]);
    $request = Request::factory()->create([
        'team_id' => $team->id,
        'customer_id' => $customer->id,
        'status' => $status,
    ]);

    // Mark this request verified for the test's session — the same flag a
    // signed visit or manual lookup would have set.
    Session::put("portal.verified.{$request->id}", true);

    return [$request, $customer];
}

test('a reply is stored as a public, customer-authored portal note', function () {
    [$request, $customer] = verifiedPortalRequest();

    Livewire::test('pages::portal.status', ['request' => $request])
        ->set('replyBody', 'Here is the extra detail you asked for.')
        ->call('reply')
        ->assertHasNoErrors()
        ->assertSet('replyBody', '');

    $note = $request->notes()->latest('id')->firstOrFail();

    expect($note->customer_id)->toBe($customer->id)
        ->and($note->user_id)->toBeNull()
        ->and($note->is_private)->toBeFalse()
        ->and($note->source)->toBe(RequestSource::Portal)
        ->and($note->body)->toBe('Here is the extra detail you asked for.');
});

test('a reply requires a body', function () {
    [$request] = verifiedPortalRequest();

    Livewire::test('pages::portal.status', ['request' => $request])
        ->set('replyBody', '')
        ->call('reply')
        ->assertHasErrors(['replyBody' => 'required']);

    expect($request->notes()->count())->toBe(0);
});

test('replying to a resolved request reopens it to active', function () {
    [$request] = verifiedPortalRequest(RequestStatus::Resolved);

    Livewire::test('pages::portal.status', ['request' => $request])
        ->set('replyBody', 'This is still broken for me.')
        ->call('reply')
        ->assertHasNoErrors();

    expect($request->fresh()->status)->toBe(RequestStatus::Active);
});

test('replying to a closed request reopens it to active', function () {
    [$request] = verifiedPortalRequest(RequestStatus::Closed);

    Livewire::test('pages::portal.status', ['request' => $request])
        ->set('replyBody', 'Reopening this please.')
        ->call('reply')
        ->assertHasNoErrors();

    expect($request->fresh()->status)->toBe(RequestStatus::Active);
});

test('replying to an active request leaves the status unchanged', function () {
    [$request] = verifiedPortalRequest(RequestStatus::Active);

    Livewire::test('pages::portal.status', ['request' => $request])
        ->set('replyBody', 'Following up.')
        ->call('reply')
        ->assertHasNoErrors();

    expect($request->fresh()->status)->toBe(RequestStatus::Active);
});

test('a new reply appears in the public timeline immediately', function () {
    [$request] = verifiedPortalRequest();

    Livewire::test('pages::portal.status', ['request' => $request])
        ->set('replyBody', 'A FRESH CUSTOMER REPLY')
        ->call('reply')
        ->assertHasNoErrors()
        ->assertSee('A FRESH CUSTOMER REPLY');
});
