<?php

use App\Enums\TeamRole;
use App\Models\Note;
use App\Models\Request;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

/*
 * The viewer roster is maintained client-side from the presence channel's
 * here/joining/leaving events. We can't drive a real websocket in a feature
 * test, but the component's handler methods ARE the state logic — calling
 * them directly proves the rules the banner depends on: self-exclusion,
 * dedupe, and the leaving cleanup. (The live two-browser behavior is the
 * manual matrix in docs/tour/07-collision-detection.md.)
 */

function actingMemberOnRequest(): array
{
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);
    $request = Request::factory()->create(['team_id' => $team->id]);

    test()->actingAs($staff);

    return [$staff, $request];
}

test('the initial roster excludes the current viewer', function () {
    [$staff, $request] = actingMemberOnRequest();

    Livewire::test('pages::requests.show', ['request' => $request])
        ->call('syncViewers', [
            ['id' => $staff->id, 'name' => $staff->name],
            ['id' => 999, 'name' => 'Riley Okonkwo'],
        ])
        ->assertSet('viewers', [999 => ['id' => 999, 'name' => 'Riley Okonkwo']]);
});

test('a viewer joining renders the collision banner', function () {
    [, $request] = actingMemberOnRequest();

    Livewire::test('pages::requests.show', ['request' => $request])
        ->assertDontSee('is also viewing this request')
        ->call('viewerJoined', ['id' => 42, 'name' => 'Riley Okonkwo'])
        ->assertSee('Riley Okonkwo is also viewing this request')
        ->assertSeeHtml('data-test="collision-banner"');
});

test('the same user in two tabs counts once', function () {
    [, $request] = actingMemberOnRequest();

    Livewire::test('pages::requests.show', ['request' => $request])
        ->call('viewerJoined', ['id' => 42, 'name' => 'Riley Okonkwo'])
        ->call('viewerJoined', ['id' => 42, 'name' => 'Riley Okonkwo'])
        ->assertCount('viewers', 1)
        ->assertSet('viewers', [42 => ['id' => 42, 'name' => 'Riley Okonkwo']]);
});

test('a viewer leaving is removed from the roster', function () {
    [, $request] = actingMemberOnRequest();

    Livewire::test('pages::requests.show', ['request' => $request])
        ->call('viewerJoined', ['id' => 42, 'name' => 'Riley Okonkwo'])
        ->call('viewerJoined', ['id' => 43, 'name' => 'Sam Carter'])
        ->call('viewerLeft', ['id' => 42, 'name' => 'Riley Okonkwo'])
        ->assertSet('viewers', [43 => ['id' => 43, 'name' => 'Sam Carter']]);
});

test('a remote NoteAdded refreshes the timeline', function () {
    [, $request] = actingMemberOnRequest();

    $component = Livewire::test('pages::requests.show', ['request' => $request])
        ->assertSee('No notes yet.');

    // A note arrives from another agent's reply after the page loaded.
    Note::factory()->create([
        'request_id' => $request->id,
        'is_private' => false,
        'body' => 'Replied while you were watching',
    ]);

    // The broadcast handler forgets the cached timeline; the next render
    // re-queries it and the new note appears without a full reload.
    $component->call('refreshTimeline')
        ->assertSee('Replied while you were watching')
        ->assertDontSee('No notes yet.');
});
