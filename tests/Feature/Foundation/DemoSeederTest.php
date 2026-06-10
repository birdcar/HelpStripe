<?php

use App\Enums\RequestStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Mailbox;
use App\Models\Note;
use App\Models\Request;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    // Freeze the clock: DemoSeeder spreads requests over "the last 60
    // days" relative to now(), so assertions about dates must not race
    // a moving clock.
    $this->travelTo(CarbonImmutable::parse('2026-06-10 12:00:00'));

    $this->seed(DatabaseSeeder::class);
});

test('the demo installation has the documented core records', function () {
    expect(Team::query()->count())->toBe(1)
        ->and(Team::query()->first()->name)->toBe('HelpStripe Support')
        ->and(User::query()->count())->toBe(4)
        ->and(Category::query()->count())->toBe(3)
        ->and(Mailbox::query()->count())->toBe(2)
        ->and(Customer::query()->count())->toBe(8)
        ->and(Request::query()->count())->toBe(40);
});

test('all four staff are members of the team with helpdesk roles', function () {
    $team = Team::query()->firstOrFail();

    expect($team->members)->toHaveCount(4);

    $admin = User::query()->where('email', 'sam@helpstripe.test')->firstOrFail();

    expect($admin->hasRole('Administrator'))->toBeTrue()
        ->and(User::role('Help Desk Staff')->count())->toBe(3);
});

test('categories carry the documented sla targets', function () {
    expect(Category::query()->where('name', 'Billing')->value('sla_first_response_minutes'))->toBe(60)
        ->and(Category::query()->where('name', 'Technical Support')->value('sla_first_response_minutes'))->toBe(240)
        ->and(Category::query()->where('name', 'Sales')->value('sla_first_response_minutes'))->toBeNull();
});

test('mailboxes route to their documented default categories', function () {
    $support = Mailbox::query()->where('address', 'support@helpstripe.test')->firstOrFail();
    $billing = Mailbox::query()->where('address', 'billing@helpstripe.test')->firstOrFail();

    expect($support->category->name)->toBe('Technical Support')
        ->and($billing->category->name)->toBe('Billing');
});

test('the request mix matches the documented shape', function () {
    expect(Request::query()->whereNull('assigned_to')->count())->toBe(10)
        ->and(Request::query()->where('is_urgent', true)->count())->toBe(4)
        ->and(Request::query()->where('status', RequestStatus::Active->value)->count())->toBe(14)
        ->and(Request::query()->where('status', RequestStatus::Pending->value)->count())->toBe(6)
        ->and(Request::query()->where('status', RequestStatus::Resolved->value)->count())->toBe(12)
        ->and(Request::query()->where('status', RequestStatus::Closed->value)->count())->toBe(8);
});

test('requests are spread across the last 60 days', function () {
    $now = CarbonImmutable::parse('2026-06-10 12:00:00');

    expect(Request::query()->where('created_at', '<', $now->subDays(30))->count())->toBeGreaterThan(0)
        ->and(Request::query()->where('created_at', '>=', $now->subDays(30))->count())->toBeGreaterThan(0)
        ->and(Request::query()->where('created_at', '<', $now->subDays(61))->count())->toBe(0)
        ->and(Request::query()->where('created_at', '>', $now)->count())->toBe(0);
});

test('every request has a timeline starting with the customer', function () {
    $requestIdsWithNotes = Note::query()->distinct()->pluck('request_id');

    expect($requestIdsWithNotes)->toHaveCount(40);

    Request::query()->with('notes')->get()->each(function (Request $request) {
        $opening = $request->notes->sortBy('created_at')->first();

        expect($opening->customer_id)->toBe($request->customer_id)
            ->and($opening->user_id)->toBeNull()
            ->and($request->notes->count())->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(6);
    });
});

test('the timeline mixes public, private, customer and staff notes', function () {
    expect(Note::query()->where('is_private', true)->count())->toBeGreaterThan(0)
        ->and(Note::query()->where('is_private', false)->count())->toBeGreaterThan(0)
        ->and(Note::query()->whereNotNull('customer_id')->count())->toBeGreaterThan(0)
        ->and(Note::query()->whereNotNull('user_id')->count())->toBeGreaterThan(0);
});

test('first responses land both inside and outside sla targets', function () {
    $respondedBilling = Request::query()
        ->whereNotNull('first_responded_at')
        ->whereHas('category', fn ($query) => $query->where('name', 'Billing'))
        ->with('category')
        ->get();

    $inside = $respondedBilling->filter(function (Request $request) {
        $minutes = (int) CarbonImmutable::parse($request->created_at)
            ->diffInMinutes(CarbonImmutable::parse($request->first_responded_at));

        return $minutes <= $request->category->sla_first_response_minutes;
    });

    expect($respondedBilling)->not->toBeEmpty()
        ->and($inside)->not->toBeEmpty()
        ->and($inside->count())->toBeLessThan($respondedBilling->count());
});

test('resolved and closed requests have resolution timestamps after creation', function () {
    Request::query()
        ->whereIn('status', [RequestStatus::Resolved->value, RequestStatus::Closed->value])
        ->get()
        ->each(function (Request $request) {
            expect($request->resolved_at)->not->toBeNull()
                ->and($request->resolved_at->isAfter($request->created_at))->toBeTrue();
        });
});

test('every request has a generated access key', function () {
    Request::query()->get()->each(function (Request $request) {
        expect($request->access_key)->toBeString()->toHaveLength(12);
    });
});
