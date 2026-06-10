<?php

use App\Enums\RequestSource;
use App\Enums\RequestStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Mailbox;
use App\Models\Note;
use App\Models\Request;
use App\Models\Team;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

test('a request can be created via its factory', function () {
    $request = Request::factory()->create();

    expect($request->exists)->toBeTrue()
        ->and($request->team)->toBeInstanceOf(Team::class)
        ->and($request->customer)->toBeInstanceOf(Customer::class)
        ->and($request->customer->team_id)->toBe($request->team_id);
});

test('a request generates a 12 character access key on create', function () {
    $request = Request::factory()->create();

    expect($request->access_key)->toBeString()->toHaveLength(12);
});

test('status and source enum casts round-trip through the database', function () {
    $request = Request::factory()->create([
        'status' => RequestStatus::Pending,
        'source' => RequestSource::Portal,
    ]);

    $fresh = Request::query()->findOrFail($request->id);

    expect($fresh->status)->toBe(RequestStatus::Pending)
        ->and($fresh->source)->toBe(RequestSource::Portal)
        ->and($fresh->getRawOriginal('status'))->toBe('pending')
        ->and($fresh->getRawOriginal('source'))->toBe('portal');
});

test('a request can exist with no category and no mailbox', function () {
    $request = Request::factory()->create([
        'category_id' => null,
        'mailbox_id' => null,
    ]);

    expect($request->category)->toBeNull()
        ->and($request->mailbox)->toBeNull();
});

test('request relations load category mailbox assignee and notes', function () {
    $team = Team::factory()->create();
    $category = Category::factory()->create(['team_id' => $team->id]);
    $mailbox = Mailbox::factory()->create(['team_id' => $team->id, 'category_id' => $category->id]);
    $staff = User::factory()->create();

    $request = Request::factory()->create([
        'team_id' => $team->id,
        'category_id' => $category->id,
        'mailbox_id' => $mailbox->id,
        'assigned_to' => $staff->id,
    ]);

    Note::factory()->count(2)->create([
        'request_id' => $request->id,
        'user_id' => $staff->id,
    ]);

    $loaded = Request::with(['category', 'mailbox', 'assignee', 'notes'])->findOrFail($request->id);

    expect($loaded->category->is($category))->toBeTrue()
        ->and($loaded->mailbox->is($mailbox))->toBeTrue()
        ->and($loaded->assignee->is($staff))->toBeTrue()
        ->and($loaded->notes)->toHaveCount(2);
});

test('a user can list their assigned requests', function () {
    $staff = User::factory()->create();
    Request::factory()->count(2)->create(['assigned_to' => $staff->id]);
    Request::factory()->create();

    expect($staff->assignedRequests)->toHaveCount(2);
});

test('changing status writes an activity log row with old and new values', function () {
    $request = Request::factory()->create(['status' => RequestStatus::Active]);

    $request->update(['status' => RequestStatus::Resolved]);

    $activity = Activity::query()
        ->where('subject_type', Request::class)
        ->where('subject_id', $request->id)
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    // activitylog v5 stores the diff in `attribute_changes`
    // (`properties` is reserved for custom withProperties() data).
    expect($activity)->not->toBeNull()
        ->and($activity->attribute_changes['attributes']['status'])->toBe('resolved')
        ->and($activity->attribute_changes['old']['status'])->toBe('active');
});

test('changing assignment writes an activity log row', function () {
    $request = Request::factory()->create(['assigned_to' => null]);
    $staff = User::factory()->create();

    $request->update(['assigned_to' => $staff->id]);

    $activity = Activity::query()
        ->where('subject_type', Request::class)
        ->where('subject_id', $request->id)
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->attribute_changes['attributes']['assigned_to'])->toBe($staff->id)
        ->and($activity->attribute_changes['old']['assigned_to'])->toBeNull();
});

test('changing an unlogged attribute writes no activity row', function () {
    $request = Request::factory()->create();

    $request->update(['subject' => 'A brand new subject line']);

    $count = Activity::query()
        ->where('subject_type', Request::class)
        ->where('subject_id', $request->id)
        ->where('event', 'updated')
        ->count();

    expect($count)->toBe(0);
});

test('tags can be attached to a request', function () {
    $request = Request::factory()->create();

    $request->attachTag('vip');
    $request->attachTag('refund');

    expect($request->tags->pluck('name')->all())->toContain('vip', 'refund');
});

test('a private note stays staff-authored and flagged private', function () {
    $note = Note::factory()->private()->create();

    expect($note->is_private)->toBeTrue()
        ->and($note->user_id)->not->toBeNull()
        ->and($note->customer_id)->toBeNull();
});

test('a note can be authored by a customer with no staff user', function () {
    $note = Note::factory()->fromCustomer()->create();

    expect($note->user_id)->toBeNull()
        ->and($note->customer)->toBeInstanceOf(Customer::class)
        ->and($note->customer_id)->toBe($note->request->customer_id)
        ->and($note->isFromCustomer())->toBeTrue();
});

test('deleting a request cascades to its notes', function () {
    $request = Request::factory()->create();
    Note::factory()->count(3)->create(['request_id' => $request->id]);

    $request->delete();

    expect(Note::query()->where('request_id', $request->id)->count())->toBe(0);
});
