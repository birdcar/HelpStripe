<?php

use App\Actions\Requests\AddNote;
use App\Actions\Requests\AssignRequest;
use App\Actions\Requests\ChangeStatus;
use App\Actions\Requests\CreateRequest;
use App\Enums\RequestSource;
use App\Enums\RequestStatus;
use App\Events\NoteAdded;
use App\Events\RequestAssigned;
use App\Events\RequestCreated;
use App\Events\RequestStatusChanged;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Request;
use App\Models\User;
use App\Notifications\RequestAssignedNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/*
 * Event::fake() is always given an explicit event list in these tests.
 * A bare Event::fake() would also fake Eloquent's model events — and
 * Request::boot() relies on `creating` to generate access keys.
 */

test('create request persists the request with its opening customer note', function () {
    $customer = Customer::factory()->create();

    $request = app(CreateRequest::class)->handle(
        $customer,
        'Printer on fire',
        'It is genuinely on fire, please advise.',
        RequestSource::Agent,
    );

    expect($request->team_id)->toBe($customer->team_id)
        ->and($request->customer_id)->toBe($customer->id)
        ->and($request->subject)->toBe('Printer on fire')
        ->and($request->status)->toBe(RequestStatus::Active)
        ->and($request->notes)->toHaveCount(1)
        ->and($request->notes->first()->body)->toBe('It is genuinely on fire, please advise.')
        ->and($request->notes->first()->customer_id)->toBe($customer->id)
        ->and($request->notes->first()->user_id)->toBeNull()
        ->and($request->notes->first()->is_private)->toBeFalse();
});

test('create request fires RequestCreated with the new request', function () {
    Event::fake([RequestCreated::class]);

    $customer = Customer::factory()->create();

    $request = app(CreateRequest::class)->handle(
        $customer,
        'Subject',
        'Body',
        RequestSource::Portal,
    );

    Event::assertDispatched(RequestCreated::class, fn (RequestCreated $event) => $event->request->is($request));
});

test('create request accepts optional category assignee and urgency', function () {
    $customer = Customer::factory()->create();
    $staff = User::factory()->create();
    $category = Category::factory()->create(['team_id' => $customer->team_id]);

    $request = app(CreateRequest::class)->handle(
        $customer,
        'Urgent billing issue',
        'Charged twice.',
        RequestSource::Agent,
        ['category_id' => $category->id, 'assigned_to' => $staff->id, 'is_urgent' => true],
    );

    expect($request->category_id)->toBe($category->id)
        ->and($request->assigned_to)->toBe($staff->id)
        ->and($request->is_urgent)->toBeTrue();
});

test('a staff public reply sets first_responded_at exactly once', function () {
    $request = Request::factory()->create();
    $staff = User::factory()->create();

    $firstReplyAt = now()->startOfSecond();
    $this->travelTo($firstReplyAt);

    app(AddNote::class)->handle($request, $staff, 'First reply');

    expect($request->refresh()->first_responded_at?->equalTo($firstReplyAt))->toBeTrue();

    // A later reply must not move the SLA timestamp.
    $this->travel(2)->hours();

    app(AddNote::class)->handle($request, $staff, 'Second reply');

    expect($request->refresh()->first_responded_at->equalTo($firstReplyAt))->toBeTrue();
});

test('a staff private note does not set first_responded_at', function () {
    $request = Request::factory()->create();
    $staff = User::factory()->create();

    $note = app(AddNote::class)->handle($request, $staff, 'Internal musings', isPrivate: true);

    expect($note->is_private)->toBeTrue()
        ->and($request->refresh()->first_responded_at)->toBeNull();
});

test('a customer note does not set first_responded_at', function () {
    $request = Request::factory()->create();

    app(AddNote::class)->handle($request, $request->customer, 'Any update?', source: RequestSource::Portal);

    expect($request->refresh()->first_responded_at)->toBeNull();
});

test('add note fires NoteAdded with the created note', function () {
    Event::fake([NoteAdded::class]);

    $request = Request::factory()->create();
    $staff = User::factory()->create();

    $note = app(AddNote::class)->handle($request, $staff, 'Reply body');

    Event::assertDispatched(NoteAdded::class, fn (NoteAdded $event) => $event->note->is($note));
});

test('assigning a request updates the assignee and fires RequestAssigned', function () {
    Event::fake([RequestAssigned::class]);
    Notification::fake();

    $request = Request::factory()->create(['assigned_to' => null]);
    $assignee = User::factory()->create();
    $actor = User::factory()->create();

    app(AssignRequest::class)->handle($request, $assignee, $actor);

    expect($request->refresh()->assigned_to)->toBe($assignee->id);

    Event::assertDispatched(
        RequestAssigned::class,
        fn (RequestAssigned $event) => $event->request->is($request)
            && $event->assignee?->is($assignee)
            && $event->previousAssignee === null,
    );
});

test('assignment notifies the new assignee on database and mail channels', function () {
    Notification::fake();

    $request = Request::factory()->create();
    $assignee = User::factory()->create();
    $actor = User::factory()->create();

    app(AssignRequest::class)->handle($request, $assignee, $actor);

    Notification::assertSentTo(
        $assignee,
        RequestAssignedNotification::class,
        function (RequestAssignedNotification $notification, array $channels) use ($request, $actor) {
            return $notification->request->is($request)
                && $notification->assignedBy?->is($actor)
                && in_array('database', $channels, true)
                && in_array('mail', $channels, true);
        },
    );
});

test('self-assignment does not notify', function () {
    Notification::fake();

    $request = Request::factory()->create();
    $staff = User::factory()->create();

    app(AssignRequest::class)->handle($request, $staff, $staff);

    expect($request->refresh()->assigned_to)->toBe($staff->id);

    Notification::assertNothingSent();
});

test('unassigning fires RequestAssigned with a null assignee and does not notify', function () {
    Event::fake([RequestAssigned::class]);
    Notification::fake();

    $previous = User::factory()->create();
    $request = Request::factory()->create(['assigned_to' => $previous->id]);
    $actor = User::factory()->create();

    app(AssignRequest::class)->handle($request, null, $actor);

    expect($request->refresh()->assigned_to)->toBeNull();

    Event::assertDispatched(
        RequestAssigned::class,
        fn (RequestAssigned $event) => $event->assignee === null && $event->previousAssignee?->is($previous),
    );

    Notification::assertNothingSent();
});

test('the assignment notification database payload identifies the request', function () {
    $request = Request::factory()->create();
    $actor = User::factory()->create();

    $payload = (new RequestAssignedNotification($request, $actor))->toArray($actor);

    expect($payload['request_id'])->toBe($request->id)
        ->and($payload['subject'])->toBe($request->subject)
        ->and($payload['team_slug'])->toBe($request->team->slug)
        ->and($payload['assigned_by'])->toBe($actor->name);
});

test('changing status to resolved stamps resolved_at and fires the event with both statuses', function () {
    Event::fake([RequestStatusChanged::class]);

    $request = Request::factory()->create(['status' => RequestStatus::Active]);

    app(ChangeStatus::class)->handle($request, RequestStatus::Resolved);

    expect($request->refresh()->status)->toBe(RequestStatus::Resolved)
        ->and($request->resolved_at)->not->toBeNull();

    Event::assertDispatched(
        RequestStatusChanged::class,
        fn (RequestStatusChanged $event) => $event->oldStatus === RequestStatus::Active
            && $event->newStatus === RequestStatus::Resolved,
    );
});

test('reopening a resolved request clears resolved_at', function () {
    $request = Request::factory()->resolved()->create();

    app(ChangeStatus::class)->handle($request, RequestStatus::Active);

    expect($request->refresh()->status)->toBe(RequestStatus::Active)
        ->and($request->resolved_at)->toBeNull();
});

test('closing an already-resolved request keeps the original resolution time', function () {
    $resolvedAt = now()->subDay()->startOfSecond();
    $request = Request::factory()->create([
        'status' => RequestStatus::Resolved,
        'resolved_at' => $resolvedAt,
    ]);

    app(ChangeStatus::class)->handle($request, RequestStatus::Closed);

    expect($request->refresh()->status)->toBe(RequestStatus::Closed)
        ->and($request->resolved_at->equalTo($resolvedAt))->toBeTrue();
});

test('a same-status change is a no-op and fires no event', function () {
    Event::fake([RequestStatusChanged::class]);

    $request = Request::factory()->create(['status' => RequestStatus::Active]);

    app(ChangeStatus::class)->handle($request, RequestStatus::Active);

    Event::assertNotDispatched(RequestStatusChanged::class);
});
