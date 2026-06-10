<?php

use App\Enums\RequestStatus;
use App\Enums\TeamRole;
use App\Models\Request;
use App\Models\Team;
use App\Models\User;
use App\Queries\Reports\AgentPerformance;
use Carbon\CarbonImmutable;

/**
 * AgentPerformance is roster-driven: one row per team member, including
 * agents with no activity (a zero row, not an absent one). Unassigned
 * requests are excluded. Exact numbers under a frozen clock.
 */
beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 12:00:00'));
    $this->team = Team::factory()->create();
    $this->from = CarbonImmutable::parse('2026-06-08 00:00:00');
    $this->to = CarbonImmutable::parse('2026-06-10 00:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * Attach a freshly created user to the test team as a member.
 */
function teamAgent(Team $team, string $name): User
{
    $user = User::factory()->create(['name' => $name, 'current_team_id' => $team->id]);
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);

    return $user;
}

test('counts open requests currently assigned to each agent', function () {
    $riley = teamAgent($this->team, 'Riley');

    Request::factory()->count(2)->create([
        'team_id' => $this->team->id,
        'assigned_to' => $riley->id,
        'status' => RequestStatus::Active,
    ]);
    Request::factory()->create([
        'team_id' => $this->team->id,
        'assigned_to' => $riley->id,
        'status' => RequestStatus::Resolved,
        'resolved_at' => CarbonImmutable::now(),
    ]);

    $row = (new AgentPerformance($this->team, $this->from, $this->to))->rows()
        ->firstWhere('id', $riley->id);

    // Two active count as open; the resolved one does not.
    expect($row->openAssigned)->toBe(2);
});

test('counts requests an agent resolved within the range', function () {
    $riley = teamAgent($this->team, 'Riley');

    Request::factory()->create([
        'team_id' => $this->team->id,
        'assigned_to' => $riley->id,
        'status' => RequestStatus::Resolved,
        'resolved_at' => CarbonImmutable::parse('2026-06-09 10:00:00'), // in range
    ]);
    Request::factory()->create([
        'team_id' => $this->team->id,
        'assigned_to' => $riley->id,
        'status' => RequestStatus::Resolved,
        'resolved_at' => CarbonImmutable::parse('2026-06-01 10:00:00'), // before range
    ]);

    $row = (new AgentPerformance($this->team, $this->from, $this->to))->rows()
        ->firstWhere('id', $riley->id);

    expect($row->resolvedInRange)->toBe(1);
});

test('averages first response minutes over the agents in-range answered requests', function () {
    $riley = teamAgent($this->team, 'Riley');

    $createdA = CarbonImmutable::parse('2026-06-08 09:00:00');
    Request::factory()->create([
        'team_id' => $this->team->id,
        'assigned_to' => $riley->id,
        'created_at' => $createdA,
        'updated_at' => $createdA,
        'first_responded_at' => $createdA->addMinutes(20),
    ]);
    $createdB = CarbonImmutable::parse('2026-06-08 11:00:00');
    Request::factory()->create([
        'team_id' => $this->team->id,
        'assigned_to' => $riley->id,
        'created_at' => $createdB,
        'updated_at' => $createdB,
        'first_responded_at' => $createdB->addMinutes(40),
    ]);

    $row = (new AgentPerformance($this->team, $this->from, $this->to))->rows()
        ->firstWhere('id', $riley->id);

    expect($row->avgFirstResponseMinutes)->toBe(30.0);
});

test('an agent with no activity still gets a zero row', function () {
    $idle = teamAgent($this->team, 'Idle Agent');

    $row = (new AgentPerformance($this->team, $this->from, $this->to))->rows()
        ->firstWhere('id', $idle->id);

    expect($row)->not->toBeNull()
        ->and($row->openAssigned)->toBe(0)
        ->and($row->resolvedInRange)->toBe(0)
        ->and($row->avgFirstResponseMinutes)->toBeNull();
});

test('excludes unassigned requests from every agent row', function () {
    $riley = teamAgent($this->team, 'Riley');

    // Unassigned, open — belongs to nobody, so no row should count it.
    Request::factory()->create([
        'team_id' => $this->team->id,
        'assigned_to' => null,
        'status' => RequestStatus::Active,
    ]);

    $rows = (new AgentPerformance($this->team, $this->from, $this->to))->rows();

    expect($rows->sum('openAssigned'))->toBe(0)
        ->and($rows->firstWhere('id', $riley->id)->openAssigned)->toBe(0);
});

test('only includes members of the queried team', function () {
    $riley = teamAgent($this->team, 'Riley');
    $other = Team::factory()->create();
    teamAgent($other, 'Outsider');

    $rows = (new AgentPerformance($this->team, $this->from, $this->to))->rows();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->id)->toBe($riley->id);
});
