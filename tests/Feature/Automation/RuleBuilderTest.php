<?php

use App\Enums\ConditionField;
use App\Enums\ConditionOperator;
use App\Enums\RuleAction;
use App\Enums\RuleLayer;
use App\Enums\TeamRole;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Livewire\Livewire;

/*
 * The rule builder UI: thin CRUD over the JSON columns, gated by the
 * 'manage automation' permission, with server-side enum validation so a
 * tampered <select> can't store a field/operator/action the engine can't read.
 */

/**
 * A staff member on a fresh team, optionally holding the Administrator role
 * (which carries 'manage automation').
 *
 * @return array{0: User, 1: Team}
 */
function automationStaffer(bool $administrator = true): array
{
    test()->seed(PermissionSeeder::class);

    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);

    $user->assignRole($administrator ? 'Administrator' : 'Help Desk Staff');

    return [$user, $team];
}

test('staff with the permission can open the builder', function () {
    [$user, $team] = automationStaffer(administrator: true);

    $this->actingAs($user)
        ->get(route('automation.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertSee('Automation');
});

test('staff without the permission get a 403', function () {
    [$user, $team] = automationStaffer(administrator: false);

    $this->actingAs($user)
        ->get(route('automation.index', ['current_team' => $team->slug]))
        ->assertForbidden();
});

test('the automation nav item is hidden from staff without the permission', function () {
    [$user, $team] = automationStaffer(administrator: false);

    $this->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertDontSee(route('automation.index', ['current_team' => $team->slug]));
});

test('a rule can be built from scratch through the UI', function () {
    [$user, $team] = automationStaffer();
    $billing = Category::factory()->create(['team_id' => $team->id, 'name' => 'Billing']);

    $this->actingAs($user);

    Livewire::test('pages::automation.edit')
        ->set('name', 'Route billing email')
        ->set('layer', 'mail')
        ->set('conditions', [
            ['field' => ConditionField::ToMailbox->value, 'operator' => ConditionOperator::Equals->value, 'value' => 'billing@helpstripe.test'],
        ])
        ->set('actions', [
            ['action' => RuleAction::SetCategory->value, 'value' => $billing->id],
        ])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('automation.index', ['current_team' => $team->slug]));

    $rule = AutomationRule::query()->where('name', 'Route billing email')->firstOrFail();

    expect($rule->team_id)->toBe($team->id)
        ->and($rule->layer)->toBe(RuleLayer::Mail)
        ->and($rule->event)->toBeNull()
        ->and($rule->conditions)->toHaveCount(1)
        ->and($rule->actions[0]['action'])->toBe(RuleAction::SetCategory->value);
});

test('a trigger rule stores its event', function () {
    [$user, $team] = automationStaffer();

    $this->actingAs($user);

    Livewire::test('pages::automation.edit')
        ->set('name', 'On create')
        ->set('layer', 'trigger')
        ->set('event', 'request_created')
        ->set('actions', [['action' => RuleAction::SetUrgent->value, 'value' => true]])
        ->call('save')
        ->assertHasNoErrors();

    expect(AutomationRule::query()->where('name', 'On create')->value('event'))->toBe('request_created');
});

test('an unknown condition operator is rejected server-side', function () {
    [$user, $team] = automationStaffer();

    $this->actingAs($user);

    Livewire::test('pages::automation.edit')
        ->set('name', 'Bad rule')
        ->set('layer', 'trigger')
        ->set('event', 'request_created')
        ->set('conditions', [
            ['field' => ConditionField::Subject->value, 'operator' => 'nonsense', 'value' => 'x'],
        ])
        ->set('actions', [['action' => RuleAction::SetUrgent->value, 'value' => true]])
        ->call('save')
        ->assertHasErrors('conditions.0.operator');

    expect(AutomationRule::query()->where('name', 'Bad rule')->exists())->toBeFalse();
});

test('a rule requires at least one action', function () {
    [$user, $team] = automationStaffer();

    $this->actingAs($user);

    Livewire::test('pages::automation.edit')
        ->set('name', 'No actions')
        ->set('layer', 'trigger')
        ->set('event', 'request_created')
        ->set('conditions', [])
        ->set('actions', [])
        ->call('save')
        ->assertHasErrors('actions');
});

test('the layer is fixed after create', function () {
    [$user, $team] = automationStaffer();
    $rule = AutomationRule::factory()->trigger('request_created')->create(['team_id' => $team->id]);

    $this->actingAs($user);

    // Attempt to switch a trigger rule to the scheduled layer — the component
    // ignores the posted layer on edit and keeps the original.
    Livewire::test('pages::automation.edit', ['rule' => $rule])
        ->set('layer', 'scheduled')
        ->call('save')
        ->assertHasNoErrors();

    expect($rule->refresh()->layer)->toBe(RuleLayer::Trigger);
});

test('toggling active flips the flag', function () {
    [$user, $team] = automationStaffer();
    $rule = AutomationRule::factory()->trigger('request_created')->create(['team_id' => $team->id, 'is_active' => true]);

    $this->actingAs($user);

    Livewire::test('pages::automation.index')->call('toggleActive', $rule->id);

    expect($rule->refresh()->is_active)->toBeFalse();
});

test('deleting a rule removes it', function () {
    [$user, $team] = automationStaffer();
    $rule = AutomationRule::factory()->trigger('request_created')->create(['team_id' => $team->id]);

    $this->actingAs($user);

    Livewire::test('pages::automation.index')->call('deleteRule', $rule->id);

    expect(AutomationRule::query()->whereKey($rule->id)->exists())->toBeFalse();
});

test('another team\'s rule 404s in the editor mount', function () {
    [$user] = automationStaffer();
    $foreignRule = AutomationRule::factory()->trigger('request_created')->create();

    $this->actingAs($user);

    Livewire::test('pages::automation.edit', ['rule' => $foreignRule])->assertStatus(404);
});
