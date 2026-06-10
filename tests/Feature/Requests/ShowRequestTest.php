<?php

use App\Enums\RequestStatus;
use App\Enums\TeamRole;
use App\Models\Category;
use App\Models\Note;
use App\Models\Request;
use App\Models\Response;
use App\Models\Team;
use App\Models\User;
use App\Notifications\RequestAssignedNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $request = Request::factory()->create();

    $this->get(route('requests.show', ['current_team' => $request->team->slug, 'request' => $request->id]))
        ->assertRedirect(route('login'));
});

test('a team member can view a request over http', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    $request = Request::factory()->create(['team_id' => $team->id, 'subject' => 'The widgets are misbehaving']);

    $this->actingAs($staff)
        ->get(route('requests.show', ['current_team' => $team->slug, 'request' => $request->id]))
        ->assertOk()
        ->assertSee('The widgets are misbehaving');
});

test('a request from another team is forbidden', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    $foreignRequest = Request::factory()->create();

    $this->actingAs($staff)
        ->get(route('requests.show', ['current_team' => $team->slug, 'request' => $foreignRequest->id]))
        ->assertForbidden();
});

test('the timeline renders customer staff and private notes distinctly', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    $request = Request::factory()->create(['team_id' => $team->id]);

    Note::factory()->create([
        'request_id' => $request->id,
        'user_id' => null,
        'customer_id' => $request->customer_id,
        'body' => 'Customer opening message',
    ]);
    Note::factory()->create([
        'request_id' => $request->id,
        'user_id' => $staff->id,
        'customer_id' => null,
        'is_private' => false,
        'body' => 'Public staff reply',
    ]);
    Note::factory()->create([
        'request_id' => $request->id,
        'user_id' => $staff->id,
        'customer_id' => null,
        'is_private' => true,
        'body' => 'Secret internal note',
    ]);

    $this->actingAs($staff);

    Livewire::test('pages::requests.show', ['request' => $request])
        ->assertSee('Customer opening message')
        ->assertSee('Public staff reply')
        ->assertSee('Secret internal note');
});

test('a request with zero notes renders the empty timeline state', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    $request = Request::factory()->create(['team_id' => $team->id]);

    $this->actingAs($staff);

    Livewire::test('pages::requests.show', ['request' => $request])
        ->assertSee('No notes yet.');
});

test('sending a public reply creates a public note and stamps first response', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    $request = Request::factory()->create(['team_id' => $team->id]);

    $this->actingAs($staff);

    Livewire::test('pages::requests.show', ['request' => $request])
        ->set('replyMode', 'public')
        ->set('replyBody', 'On it — checking now.')
        ->call('addNote')
        ->assertHasNoErrors();

    $note = $request->notes()->sole();

    expect($note->user_id)->toBe($staff->id)
        ->and($note->is_private)->toBeFalse()
        ->and($note->body)->toBe('On it — checking now.')
        ->and($request->refresh()->first_responded_at)->not->toBeNull();
});

test('adding a private note does not stamp first response', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    $request = Request::factory()->create(['team_id' => $team->id]);

    $this->actingAs($staff);

    Livewire::test('pages::requests.show', ['request' => $request])
        ->set('replyMode', 'private')
        ->set('replyBody', 'Customer is a VIP, escalate fast.')
        ->call('addNote')
        ->assertHasNoErrors();

    $note = $request->notes()->sole();

    expect($note->is_private)->toBeTrue()
        ->and($request->refresh()->first_responded_at)->toBeNull();
});

test('an empty reply body is rejected with a validation error', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    $request = Request::factory()->create(['team_id' => $team->id]);

    $this->actingAs($staff);

    Livewire::test('pages::requests.show', ['request' => $request])
        ->set('replyBody', '')
        ->call('addNote')
        ->assertHasErrors(['replyBody' => 'required']);

    expect($request->notes()->count())->toBe(0);
});

test('picking a canned response inserts its body into the reply draft', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    $request = Request::factory()->create(['team_id' => $team->id]);
    $response = Response::factory()->create(['team_id' => $team->id, 'body' => 'Canned reply body text.']);

    $this->actingAs($staff);

    Livewire::test('pages::requests.show', ['request' => $request])
        ->set('selectedResponse', (string) $response->id)
        ->assertSet('replyBody', 'Canned reply body text.');
});

test('picking a canned response appends to an existing draft', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    $request = Request::factory()->create(['team_id' => $team->id]);
    $response = Response::factory()->create(['team_id' => $team->id, 'body' => 'Canned tail.']);

    $this->actingAs($staff);

    Livewire::test('pages::requests.show', ['request' => $request])
        ->set('replyBody', 'Hand-typed opener.')
        ->set('selectedResponse', (string) $response->id)
        ->assertSet('replyBody', "Hand-typed opener.\n\nCanned tail.");
});

test('a canned response from another team is ignored', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    $request = Request::factory()->create(['team_id' => $team->id]);
    $foreignResponse = Response::factory()->create(['body' => 'Foreign canned body.']);

    $this->actingAs($staff);

    Livewire::test('pages::requests.show', ['request' => $request])
        ->set('selectedResponse', (string) $foreignResponse->id)
        ->assertSet('replyBody', '');
});

test('the response picker copes with an empty responses table', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    $request = Request::factory()->create(['team_id' => $team->id]);

    $this->actingAs($staff);

    Livewire::test('pages::requests.show', ['request' => $request])
        ->assertSee('Insert a canned Response…');
});

test('changing status through the properties panel resolves the request', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    $request = Request::factory()->create(['team_id' => $team->id, 'status' => RequestStatus::Active]);

    $this->actingAs($staff);

    Livewire::test('pages::requests.show', ['request' => $request])
        ->set('status', 'resolved');

    expect($request->refresh()->status)->toBe(RequestStatus::Resolved)
        ->and($request->resolved_at)->not->toBeNull();
});

test('an invalid status value is rejected and the request is unchanged', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    $request = Request::factory()->create(['team_id' => $team->id, 'status' => RequestStatus::Active]);

    $this->actingAs($staff);

    Livewire::test('pages::requests.show', ['request' => $request])
        ->set('status', 'banana')
        ->assertSet('status', 'active');

    expect($request->refresh()->status)->toBe(RequestStatus::Active);
});

test('assigning from the properties panel updates the request and notifies', function () {
    Notification::fake();

    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $teammate = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);
    $team->members()->attach($teammate, ['role' => TeamRole::Member->value]);

    $request = Request::factory()->create(['team_id' => $team->id, 'assigned_to' => null]);

    $this->actingAs($staff);

    Livewire::test('pages::requests.show', ['request' => $request])
        ->set('assignee', (string) $teammate->id);

    expect($request->refresh()->assigned_to)->toBe($teammate->id);

    Notification::assertSentTo($teammate, RequestAssignedNotification::class);
});

test('clearing the assignee unassigns the request', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    $request = Request::factory()->create(['team_id' => $team->id, 'assigned_to' => $staff->id]);

    $this->actingAs($staff);

    Livewire::test('pages::requests.show', ['request' => $request])
        ->set('assignee', '');

    expect($request->refresh()->assigned_to)->toBeNull();
});

test('changing category and urgency persists through the properties panel', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    $category = Category::factory()->create(['team_id' => $team->id]);
    $request = Request::factory()->create(['team_id' => $team->id, 'category_id' => null, 'is_urgent' => false]);

    $this->actingAs($staff);

    Livewire::test('pages::requests.show', ['request' => $request])
        ->set('category', (string) $category->id)
        ->set('urgent', true);

    expect($request->refresh()->category_id)->toBe($category->id)
        ->and($request->is_urgent)->toBeTrue();
});

test('the tags input syncs spatie tags', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    $request = Request::factory()->create(['team_id' => $team->id]);

    $this->actingAs($staff);

    Livewire::test('pages::requests.show', ['request' => $request])
        ->set('tags', 'vip, refund');

    expect($request->refresh()->tags->pluck('name')->all())->toContain('vip', 'refund');

    // Re-syncing with one tag removed detaches it.
    Livewire::test('pages::requests.show', ['request' => $request])
        ->set('tags', 'vip');

    expect($request->refresh()->tags->pluck('name')->all())->toBe(['vip']);
});

test('the history tab humanizes activity log entries', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['name' => 'Sam Agent', 'current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    $request = Request::factory()->create(['team_id' => $team->id, 'status' => RequestStatus::Active]);

    $this->actingAs($staff);

    // Drive the change through the page so the activity has a causer.
    Livewire::test('pages::requests.show', ['request' => $request])
        ->set('status', 'resolved')
        ->assertSee('Sam Agent')
        ->assertSee('changed status from Active to Resolved');
});

test('the customer card links the customers other requests', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    $request = Request::factory()->create(['team_id' => $team->id]);
    $other = Request::factory()->create([
        'team_id' => $team->id,
        'customer_id' => $request->customer_id,
        'subject' => 'Their earlier saga',
    ]);

    $this->actingAs($staff);

    Livewire::test('pages::requests.show', ['request' => $request])
        ->assertSee($request->customer->name)
        ->assertSee($request->customer->email)
        ->assertSee('Their earlier saga');
});
