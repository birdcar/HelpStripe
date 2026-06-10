<?php

use App\Enums\RequestSource;
use App\Enums\RequestStatus;
use App\Mail\NewRequestConfirmationMail;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Request;
use App\Models\Team;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
 * The portal submit flow. Customers have no accounts: a submission creates
 * (or reuses) a Customer row by email and opens a Request via the shared
 * CreateRequest action with RequestSource::Portal. The access key rides the
 * confirmation email, never the page.
 */

/**
 * The single installation team the portal lands requests on (the portal has
 * no tenant context, mirroring the email + API channels).
 */
function portalTeam(): Team
{
    return Team::factory()->create();
}

test('the submit page renders without authentication', function () {
    portalTeam();

    $this->assertGuest();

    $this->get(route('portal.submit'))->assertOk();
});

test('a valid submission opens a portal-source request and queues the confirmation mail', function () {
    Mail::fake();

    $team = portalTeam();

    Livewire::test('pages::portal.submit')
        ->set('name', 'Dana Customer')
        ->set('email', 'dana@example.com')
        ->set('subject', 'Cannot log in')
        ->set('body', 'I keep getting an error when I try to sign in.')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('submittedRequestId', fn ($id) => is_int($id) && $id > 0);

    $request = Request::query()->firstOrFail();

    expect($request->source)->toBe(RequestSource::Portal)
        ->and($request->team_id)->toBe($team->id)
        ->and($request->subject)->toBe('Cannot log in')
        ->and($request->status)->toBe(RequestStatus::Active)
        ->and($request->customer->email)->toBe('dana@example.com')
        ->and($request->notes()->where('is_private', false)->count())->toBe(1)
        ->and($request->access_key)->toHaveLength(12);

    Mail::assertQueued(
        NewRequestConfirmationMail::class,
        fn (NewRequestConfirmationMail $mail) => $mail->hasTo('dana@example.com')
            && $mail->request->is($request),
    );
});

test('the confirmation state shows the request number but never the access key', function () {
    Mail::fake();

    portalTeam();

    Livewire::test('pages::portal.submit')
        ->set('name', 'Dana Customer')
        ->set('email', 'dana@example.com')
        ->set('subject', 'Cannot log in')
        ->set('body', 'Details about the problem.')
        ->call('submit')
        ->assertSee('Request received')
        ->assertSee('#'.Request::query()->value('id'))
        ->assertDontSee(Request::query()->value('access_key'));
});

test('an optional category is attached when it belongs to the installation', function () {
    Mail::fake();

    $team = portalTeam();
    $category = Category::factory()->create(['team_id' => $team->id, 'name' => 'Billing']);

    Livewire::test('pages::portal.submit')
        ->set('name', 'Dana Customer')
        ->set('email', 'dana@example.com')
        ->set('category', (string) $category->id)
        ->set('subject', 'Refund question')
        ->set('body', 'I was charged twice.')
        ->call('submit')
        ->assertHasNoErrors();

    expect(Request::query()->value('category_id'))->toBe($category->id);
});

test('a category id from another installation is dropped, not trusted', function () {
    Mail::fake();

    portalTeam();
    $foreignCategory = Category::factory()->create(['name' => 'Foreign']);

    Livewire::test('pages::portal.submit')
        ->set('name', 'Dana Customer')
        ->set('email', 'dana@example.com')
        ->set('category', (string) $foreignCategory->id)
        ->set('subject', 'Some subject')
        ->set('body', 'Some body.')
        ->call('submit')
        ->assertHasNoErrors();

    expect(Request::query()->value('category_id'))->toBeNull();
});

test('submission requires name, email, subject, and body', function () {
    portalTeam();

    Livewire::test('pages::portal.submit')
        ->call('submit')
        ->assertHasErrors([
            'name' => 'required',
            'email' => 'required',
            'subject' => 'required',
            'body' => 'required',
        ]);

    expect(Request::query()->count())->toBe(0);
});

test('submission rejects an invalid email address', function () {
    portalTeam();

    Livewire::test('pages::portal.submit')
        ->set('name', 'Dana Customer')
        ->set('email', 'not-an-email')
        ->set('subject', 'Subject')
        ->set('body', 'Body.')
        ->call('submit')
        ->assertHasErrors(['email' => 'email']);
});

test('an existing customer is reused by email and keeps their original name', function () {
    Mail::fake();

    $team = portalTeam();
    $existing = Customer::factory()->create([
        'team_id' => $team->id,
        'name' => 'Original Name',
        'email' => 'repeat@example.com',
    ]);

    // Same person, different casing, a new display name on this submission.
    Livewire::test('pages::portal.submit')
        ->set('name', 'A Different Name')
        ->set('email', 'Repeat@Example.com')
        ->set('subject', 'Second request')
        ->set('body', 'My second issue.')
        ->call('submit')
        ->assertHasNoErrors();

    expect(Customer::query()->where('team_id', $team->id)->count())->toBe(1)
        ->and($existing->fresh()->name)->toBe('Original Name')
        ->and(Request::query()->value('customer_id'))->toBe($existing->id);
});

test('the submit route throttles after ten requests in a minute', function () {
    portalTeam();

    foreach (range(1, 10) as $ignored) {
        $this->get(route('portal.submit'))->assertOk();
    }

    $this->get(route('portal.submit'))->assertStatus(429);
});
