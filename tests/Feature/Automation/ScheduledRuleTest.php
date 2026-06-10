<?php

use App\Enums\ConditionField;
use App\Enums\ConditionOperator;
use App\Enums\RequestStatus;
use App\Enums\RuleAction;
use App\Models\AutomationRule;
use App\Models\Request;
use App\Models\Team;
use Carbon\CarbonImmutable;

/*
 * The scheduled layer driven by `automation:run`: it scans the open queue,
 * applies matching scheduled rules, and stamps last_run_at. Frozen time +
 * the factory's `aged` state pin exact request ages so the 24h boundary and
 * idempotence are deterministic.
 */

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 12:00:00'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * The seeded-pattern rule: "older than 24h AND not yet urgent → set urgent."
 * Self-extinguishing — once urgent, the is_urgent=false clause fails, so a
 * second run can't re-match it. This is the idempotence-by-design pattern.
 */
function escalateStaleRule(Team $team): AutomationRule
{
    return AutomationRule::factory()->scheduled()->create([
        'team_id' => $team->id,
        'name' => 'Escalate stale requests',
        'conditions' => [
            ['field' => ConditionField::AgeHours->value, 'operator' => ConditionOperator::GreaterThan->value, 'value' => 24],
            ['field' => ConditionField::IsUrgent->value, 'operator' => ConditionOperator::Equals->value, 'value' => false],
        ],
        'actions' => [
            ['action' => RuleAction::SetUrgent->value, 'value' => true],
        ],
    ]);
}

test('automation:run escalates a 25h-old request but leaves a 23h-old one', function () {
    $team = Team::factory()->create();
    escalateStaleRule($team);

    $stale = Request::factory()->create([
        'team_id' => $team->id,
        'status' => RequestStatus::Active,
        'is_urgent' => false,
        'created_at' => CarbonImmutable::now()->subHours(25),
        'updated_at' => CarbonImmutable::now()->subHours(25),
    ]);
    $fresh = Request::factory()->create([
        'team_id' => $team->id,
        'status' => RequestStatus::Active,
        'is_urgent' => false,
        'created_at' => CarbonImmutable::now()->subHours(23),
        'updated_at' => CarbonImmutable::now()->subHours(23),
    ]);

    $this->artisan('automation:run')->assertSuccessful();

    expect($stale->refresh()->is_urgent)->toBeTrue()
        ->and($fresh->refresh()->is_urgent)->toBeFalse();
});

test('running automation:run twice is a no-op the second time (idempotence by design)', function () {
    $team = Team::factory()->create();
    escalateStaleRule($team);

    $stale = Request::factory()->create([
        'team_id' => $team->id,
        'status' => RequestStatus::Active,
        'is_urgent' => false,
        'created_at' => CarbonImmutable::now()->subHours(30),
        'updated_at' => CarbonImmutable::now()->subHours(30),
    ]);

    $this->artisan('automation:run')->assertSuccessful();
    expect($stale->refresh()->is_urgent)->toBeTrue();

    // After the first run the request is urgent, so the is_urgent=false clause
    // no longer matches — the second run touches nothing. We prove that by the
    // absence of any *new* activity from the second run.
    $activityCountAfterFirst = $stale->activitiesAsSubject()->count();

    $this->artisan('automation:run')->assertSuccessful();

    expect($stale->refresh()->is_urgent)->toBeTrue()
        ->and($stale->activitiesAsSubject()->count())->toBe($activityCountAfterFirst);
});

test('resolved and closed requests are skipped', function () {
    $team = Team::factory()->create();
    escalateStaleRule($team);

    $resolved = Request::factory()->create([
        'team_id' => $team->id,
        'status' => RequestStatus::Resolved,
        'is_urgent' => false,
        'created_at' => CarbonImmutable::now()->subHours(40),
        'updated_at' => CarbonImmutable::now()->subHours(40),
    ]);
    $closed = Request::factory()->create([
        'team_id' => $team->id,
        'status' => RequestStatus::Closed,
        'is_urgent' => false,
        'created_at' => CarbonImmutable::now()->subHours(40),
        'updated_at' => CarbonImmutable::now()->subHours(40),
    ]);

    $this->artisan('automation:run')->assertSuccessful();

    expect($resolved->refresh()->is_urgent)->toBeFalse()
        ->and($closed->refresh()->is_urgent)->toBeFalse();
});

test('automation:run stamps last_run_at on every evaluated rule', function () {
    $team = Team::factory()->create();
    $rule = escalateStaleRule($team);

    expect($rule->last_run_at)->toBeNull();

    $this->artisan('automation:run')->assertSuccessful();

    expect($rule->refresh()->last_run_at)->not->toBeNull();
});

test('an inactive scheduled rule never runs', function () {
    $team = Team::factory()->create();
    AutomationRule::factory()->scheduled()->inactive()->create([
        'team_id' => $team->id,
        'conditions' => [
            ['field' => ConditionField::AgeHours->value, 'operator' => ConditionOperator::GreaterThan->value, 'value' => 1],
        ],
        'actions' => [['action' => RuleAction::SetUrgent->value, 'value' => true]],
    ]);

    $request = Request::factory()->create([
        'team_id' => $team->id,
        'status' => RequestStatus::Active,
        'is_urgent' => false,
        'created_at' => CarbonImmutable::now()->subHours(40),
        'updated_at' => CarbonImmutable::now()->subHours(40),
    ]);

    $this->artisan('automation:run')->assertSuccessful();

    expect($request->refresh()->is_urgent)->toBeFalse();
});
