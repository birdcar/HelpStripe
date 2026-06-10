<?php

use App\Enums\TeamRole;
use App\Models\Filter;
use App\Models\Request;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

test('the save filter modal persists the current criteria as a named filter', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    $this->actingAs($staff);

    Livewire::test('pages::requests.save-filter-modal')
        ->call('open', ['status' => 'active', 'assignee' => 'me', 'category_id' => '', 'urgent' => false, 'search' => ''])
        ->set('name', 'My Active')
        ->set('isShared', true)
        ->call('save')
        ->assertHasNoErrors();

    $filter = Filter::query()->where('name', 'My Active')->sole();

    expect($filter->team_id)->toBe($team->id)
        ->and($filter->user_id)->toBe($staff->id)
        ->and($filter->is_shared)->toBeTrue()
        ->and($filter->criteria['status'])->toBe('active')
        ->and($filter->criteria['assignee'])->toBe('me');
});

test('the save filter modal requires a name', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    $this->actingAs($staff);

    Livewire::test('pages::requests.save-filter-modal')
        ->call('open', ['status' => 'active'])
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);

    expect(Filter::query()->count())->toBe(0);
});

test('applying a saved filter loads its criteria and narrows the queue', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    Request::factory()->urgent()->create(['team_id' => $team->id, 'subject' => 'Urgent unowned', 'assigned_to' => null]);
    Request::factory()->create(['team_id' => $team->id, 'subject' => 'Calm and owned', 'assigned_to' => $staff->id]);

    $filter = Filter::factory()->create([
        'team_id' => $team->id,
        'user_id' => $staff->id,
        'name' => 'Urgent Unassigned',
        'criteria' => ['assignee' => 'unassigned', 'urgent' => true],
    ]);

    $this->actingAs($staff);

    Livewire::test('pages::requests.index')
        ->call('applyFilter', $filter->id)
        ->assertSet('assignee', 'unassigned')
        ->assertSet('urgent', true)
        ->assertSee('Urgent unowned')
        ->assertDontSee('Calm and owned');
});

test('a shared filter saved with me applies relative to the viewer', function () {
    $team = Team::factory()->create();
    $author = User::factory()->create(['current_team_id' => $team->id]);
    $viewer = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($author, ['role' => TeamRole::Member->value]);
    $team->members()->attach($viewer, ['role' => TeamRole::Member->value]);

    Request::factory()->create(['team_id' => $team->id, 'subject' => 'Belongs to viewer', 'assigned_to' => $viewer->id]);
    Request::factory()->create(['team_id' => $team->id, 'subject' => 'Belongs to author', 'assigned_to' => $author->id]);

    $filter = Filter::factory()->shared()->create([
        'team_id' => $team->id,
        'user_id' => $author->id,
        'name' => 'My Requests',
        'criteria' => ['assignee' => 'me'],
    ]);

    $this->actingAs($viewer);

    Livewire::test('pages::requests.index')
        ->call('applyFilter', $filter->id)
        ->assertSee('Belongs to viewer')
        ->assertDontSee('Belongs to author');
});

test('shared filters are listed for teammates but private ones are not', function () {
    $team = Team::factory()->create();
    $author = User::factory()->create(['current_team_id' => $team->id]);
    $viewer = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($author, ['role' => TeamRole::Member->value]);
    $team->members()->attach($viewer, ['role' => TeamRole::Member->value]);

    Filter::factory()->shared()->create(['team_id' => $team->id, 'user_id' => $author->id, 'name' => 'Team-wide view']);
    Filter::factory()->create(['team_id' => $team->id, 'user_id' => $author->id, 'name' => 'Authors secret stash']);

    $this->actingAs($viewer);

    Livewire::test('pages::requests.index')
        ->assertSee('Team-wide view')
        ->assertDontSee('Authors secret stash');
});

test('applying a teammates private filter is rejected', function () {
    $team = Team::factory()->create();
    $author = User::factory()->create(['current_team_id' => $team->id]);
    $viewer = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($author, ['role' => TeamRole::Member->value]);
    $team->members()->attach($viewer, ['role' => TeamRole::Member->value]);

    $private = Filter::factory()->create(['team_id' => $team->id, 'user_id' => $author->id]);

    $this->actingAs($viewer);

    expect(fn () => Livewire::test('pages::requests.index')->call('applyFilter', $private->id))
        ->toThrow(ModelNotFoundException::class);
});

test('a filter from another team is not applicable even when shared', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    $foreign = Filter::factory()->shared()->create();

    $this->actingAs($staff);

    expect(fn () => Livewire::test('pages::requests.index')->call('applyFilter', $foreign->id))
        ->toThrow(ModelNotFoundException::class);
});

test('legacy criteria with unknown keys still apply the known ones', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    Request::factory()->urgent()->create(['team_id' => $team->id, 'subject' => 'Urgent thing']);
    Request::factory()->create(['team_id' => $team->id, 'subject' => 'Calm thing']);

    // A criteria shape from "the future": unknown keys must be ignored,
    // known keys still honored.
    $filter = Filter::factory()->create([
        'team_id' => $team->id,
        'user_id' => $staff->id,
        'criteria' => ['urgent' => true, 'sla_breached' => true, 'sort' => 'oldest-first'],
    ]);

    $this->actingAs($staff);

    Livewire::test('pages::requests.index')
        ->call('applyFilter', $filter->id)
        ->assertSee('Urgent thing')
        ->assertDontSee('Calm thing');
});
