<?php

use App\Enums\RequestStatus;
use App\Enums\RuleAction;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\Request;
use App\Models\Team;
use App\Models\User;
use App\Support\Automation\RuleEngine;
use Carbon\CarbonImmutable;

/*
 * The engine's named edge cases (spec Testing Requirements + Error Handling):
 * empty actions, an action referencing a deleted entity, a malformed
 * (hand-edited) rule, and two scheduled rules touching one request in a run.
 */

/**
 * Build a team with one staff member who can author system notes.
 *
 * @return array{0: Team, 1: User}
 */
function automationTeam(): array
{
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => 'owner']);

    return [$team, $staff];
}

test('a rule with empty actions matches, does nothing, and logs the marker', function () {
    [$team] = automationTeam();
    $request = Request::factory()->create(['team_id' => $team->id, 'is_urgent' => false]);

    $rule = AutomationRule::factory()->trigger('request_created')->create([
        'team_id' => $team->id,
        'name' => 'No-op rule',
        'conditions' => [],
        'actions' => [],
    ]);

    expect(app(RuleEngine::class)->runRule($rule, $request))->toBeTrue();

    // No field changed, but the cause marker was logged.
    expect($request->refresh()->is_urgent)->toBeFalse()
        ->and($request->activitiesAsSubject()->where('description', 'Trigger: No-op rule')->exists())->toBeTrue();
});

test('an action referencing a deleted category is skipped with a private note', function () {
    [$team] = automationTeam();
    $request = Request::factory()->create(['team_id' => $team->id]);

    $rule = AutomationRule::factory()->trigger('request_created')->create([
        'team_id' => $team->id,
        'conditions' => [],
        // 999999 is a category id that does not exist.
        'actions' => [
            ['action' => RuleAction::SetCategory->value, 'value' => 999999],
            ['action' => RuleAction::SetUrgent->value, 'value' => true],
        ],
    ]);

    app(RuleEngine::class)->runRule($rule, $request);

    $request->refresh();

    // The bad category action was skipped (category stays null) but the rule
    // continued — the set_urgent action after it still ran.
    expect($request->category_id)->toBeNull()
        ->and($request->is_urgent)->toBeTrue();

    $skipNote = $request->notes()->where('is_private', true)->latest('id')->first();
    expect($skipNote)->not->toBeNull()
        ->and($skipNote->body)->toContain('action skipped');
});

test('a malformed rule is skipped, logged, and does not halt the engine', function () {
    [$team] = automationTeam();
    $request = Request::factory()->create(['team_id' => $team->id, 'is_urgent' => false]);

    // A hand-edited rule whose condition operator is not a known enum value —
    // hydration throws, the engine catches it and returns false (no apply).
    $rule = AutomationRule::factory()->trigger('request_created')->create([
        'team_id' => $team->id,
        'conditions' => [
            ['field' => 'subject', 'operator' => 'totally-bogus', 'value' => 'x'],
        ],
        'actions' => [['action' => RuleAction::SetUrgent->value, 'value' => true]],
    ]);

    expect(app(RuleEngine::class)->runRule($rule, $request))->toBeFalse()
        ->and($request->refresh()->is_urgent)->toBeFalse();
});

test('two scheduled rules can both touch one request in a single run', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 12:00:00'));

    [$team] = automationTeam();
    $billing = Category::factory()->create(['team_id' => $team->id]);
    $request = Request::factory()->create([
        'team_id' => $team->id,
        'status' => RequestStatus::Active,
        'is_urgent' => false,
        'category_id' => null,
        'created_at' => CarbonImmutable::now()->subHours(30),
        'updated_at' => CarbonImmutable::now()->subHours(30),
    ]);

    AutomationRule::factory()->scheduled()->create([
        'team_id' => $team->id,
        'name' => 'Escalate',
        'position' => 1,
        'conditions' => [['field' => 'age_hours', 'operator' => 'gt', 'value' => 24]],
        'actions' => [['action' => RuleAction::SetUrgent->value, 'value' => true]],
    ]);
    AutomationRule::factory()->scheduled()->create([
        'team_id' => $team->id,
        'name' => 'Categorize',
        'position' => 2,
        'conditions' => [['field' => 'age_hours', 'operator' => 'gt', 'value' => 24]],
        'actions' => [['action' => RuleAction::SetCategory->value, 'value' => $billing->id]],
    ]);

    $this->artisan('automation:run')->assertSuccessful();

    $request->refresh();

    expect($request->is_urgent)->toBeTrue()
        ->and($request->category_id)->toBe($billing->id);

    CarbonImmutable::setTestNow();
});
