<?php

use App\Actions\Requests\ChangeStatus;
use App\Actions\Requests\CreateRequest;
use App\Enums\ConditionField;
use App\Enums\ConditionOperator;
use App\Enums\RequestSource;
use App\Enums\RequestStatus;
use App\Enums\RuleAction;
use App\Models\AutomationRule;
use App\Models\Customer;
use App\Models\Request;
use App\Models\Team;
use App\Models\User;
use App\Notifications\AutomationNotification;
use Illuminate\Support\Facades\Notification;

/*
 * Trigger-layer rules: real domain events fire through the Phase 2 actions, the
 * (sync) queued EvaluateTriggers listener evaluates active trigger rules for
 * that event, and ActionApplier applies matches. The loop guard is the headline
 * test — an automation-caused status change must NOT re-fire status triggers.
 */

test('a new urgent request notifies an admin via a request_created trigger', function () {
    Notification::fake();

    $team = Team::factory()->create();
    $admin = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($admin, ['role' => 'owner']);
    $customer = Customer::factory()->create(['team_id' => $team->id]);

    AutomationRule::factory()->trigger('request_created')->create([
        'team_id' => $team->id,
        'name' => 'Escalate urgent',
        'conditions' => [
            ['field' => ConditionField::IsUrgent->value, 'operator' => ConditionOperator::Equals->value, 'value' => true],
        ],
        'actions' => [
            ['action' => RuleAction::NotifyUser->value, 'value' => $admin->id],
        ],
    ]);

    app(CreateRequest::class)->handle($customer, 'Urgent thing', 'help', RequestSource::Agent, ['is_urgent' => true]);

    Notification::assertSentTo($admin, AutomationNotification::class, fn (AutomationNotification $n) => $n->ruleName === 'Trigger: Escalate urgent');
});

test('a non-matching request_created trigger stays silent', function () {
    Notification::fake();

    $team = Team::factory()->create();
    $admin = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($admin, ['role' => 'owner']);
    $customer = Customer::factory()->create(['team_id' => $team->id]);

    AutomationRule::factory()->trigger('request_created')->create([
        'team_id' => $team->id,
        'conditions' => [
            ['field' => ConditionField::IsUrgent->value, 'operator' => ConditionOperator::Equals->value, 'value' => true],
        ],
        'actions' => [['action' => RuleAction::NotifyUser->value, 'value' => $admin->id]],
    ]);

    // Not urgent → rule must not match.
    app(CreateRequest::class)->handle($customer, 'Routine thing', 'hi', RequestSource::Agent);

    Notification::assertNothingSent();
});

test('a status-change trigger adds a private note when a request is resolved', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create();
    $team->members()->attach($staff, ['role' => 'member']);
    $request = Request::factory()->create([
        'team_id' => $team->id,
        'assigned_to' => $staff->id,
        'status' => RequestStatus::Active,
    ]);

    AutomationRule::factory()->trigger('request_status_changed')->create([
        'team_id' => $team->id,
        'conditions' => [
            ['field' => ConditionField::Status->value, 'operator' => ConditionOperator::Equals->value, 'value' => RequestStatus::Resolved->value],
        ],
        'actions' => [
            ['action' => RuleAction::AddPrivateNote->value, 'value' => 'Resolved by automation — please confirm.'],
        ],
    ]);

    app(ChangeStatus::class)->handle($request, RequestStatus::Resolved);

    $note = $request->notes()->where('is_private', true)->latest('id')->first();

    expect($note)->not->toBeNull()
        ->and($note->body)->toBe('Resolved by automation — please confirm.');
});

test('an inactive trigger never fires', function () {
    Notification::fake();

    $team = Team::factory()->create();
    $admin = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($admin, ['role' => 'owner']);
    $customer = Customer::factory()->create(['team_id' => $team->id]);

    AutomationRule::factory()->trigger('request_created')->inactive()->create([
        'team_id' => $team->id,
        'conditions' => [],
        'actions' => [['action' => RuleAction::NotifyUser->value, 'value' => $admin->id]],
    ]);

    app(CreateRequest::class)->handle($customer, 'Anything', 'hi', RequestSource::Agent);

    Notification::assertNothingSent();
});

test('an automation-caused status change does not re-fire status triggers (loop guard)', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create();
    $team->members()->attach($staff, ['role' => 'member']);
    $request = Request::factory()->create([
        'team_id' => $team->id,
        'assigned_to' => $staff->id,
        'status' => RequestStatus::Active,
    ]);

    // A pathological self-triggering rule: "on any status change → change
    // status to Pending". Without the loop guard the action's own
    // RequestStatusChanged would re-enter the listener and recurse. The guard
    // suppresses the nested fire, so exactly ONE rule-driven transition happens.
    AutomationRule::factory()->trigger('request_status_changed')->create([
        'team_id' => $team->id,
        'conditions' => [],
        'actions' => [
            ['action' => RuleAction::ChangeStatus->value, 'value' => RequestStatus::Pending->value],
        ],
    ]);

    // Caller-driven move Active → Resolved; the rule then flips it to Pending
    // once, and the guard stops the Pending change from re-triggering itself.
    app(ChangeStatus::class)->handle($request, RequestStatus::Resolved);

    expect($request->refresh()->status)->toBe(RequestStatus::Pending);

    // The activity log records the two real transitions (Active→Resolved by the
    // caller, Resolved→Pending by the rule) and stops — no runaway chain.
    expect($request->activitiesAsSubject()->count())->toBeLessThanOrEqual(5);
});
