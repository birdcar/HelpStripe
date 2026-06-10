<?php

use App\Enums\RequestStatus;
use App\Models\Category;
use App\Models\Request;
use App\Models\Team;
use App\Queries\Reports\QueueSnapshot;
use Carbon\CarbonImmutable;

/**
 * QueueSnapshot is point-in-time, not range-scoped: open/unassigned/urgent are
 * current-state counts; breached/overdue route through the shared SLA scopes.
 */
beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 12:00:00'));
    $this->team = Team::factory()->create();
    $this->billing = Category::factory()->create([
        'team_id' => $this->team->id,
        'sla_first_response_minutes' => 60,
    ]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('reports open, unassigned, urgent, breached, and overdue counts', function () {
    // Open + assigned + in SLA.
    Request::factory()->create([
        'team_id' => $this->team->id,
        'category_id' => $this->billing->id,
        'status' => RequestStatus::Active,
        'assigned_to' => null,
        'is_urgent' => true,
        'created_at' => CarbonImmutable::now()->subMinutes(90), // overdue & unanswered
        'updated_at' => CarbonImmutable::now()->subMinutes(90),
        'first_responded_at' => null,
    ]);

    // Open, assigned, answered in SLA — not a breach.
    $created = CarbonImmutable::now()->subMinutes(120);
    Request::factory()->create([
        'team_id' => $this->team->id,
        'category_id' => $this->billing->id,
        'status' => RequestStatus::Pending,
        'assigned_to' => null,
        'created_at' => $created,
        'updated_at' => $created,
        'first_responded_at' => $created->addMinutes(30),
    ]);

    // Resolved — not open.
    Request::factory()->create([
        'team_id' => $this->team->id,
        'category_id' => $this->billing->id,
        'status' => RequestStatus::Resolved,
        'resolved_at' => CarbonImmutable::now(),
    ]);

    $counts = (new QueueSnapshot($this->team))->counts();

    expect($counts['open'])->toBe(2)        // the active + pending
        ->and($counts['unassigned'])->toBe(2) // both open ones are unassigned
        ->and($counts['urgent'])->toBe(1)     // only the first
        ->and($counts['breached'])->toBe(1)   // the overdue unanswered one
        ->and($counts['overdue'])->toBe(1);
});

test('only counts the queried team', function () {
    $other = Team::factory()->create();
    Request::factory()->create(['team_id' => $this->team->id, 'status' => RequestStatus::Active]);
    Request::factory()->create(['team_id' => $other->id, 'status' => RequestStatus::Active]);

    expect((new QueueSnapshot($this->team))->counts()['open'])->toBe(1);
});
