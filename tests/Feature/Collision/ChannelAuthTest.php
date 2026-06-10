<?php

use App\Enums\TeamRole;
use App\Models\Request;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Config;

/*
 * Channel authorization is the websocket gate. The browser never joins a
 * presence channel directly — Echo first POSTs to /broadcasting/auth with
 * the channel name + its socket id, and the closure in routes/channels.php
 * decides. These tests drive that endpoint exactly the way the browser
 * does, so the auth matrix (member / non-member / guest) is proven without
 * a running Reverb server.
 *
 * Presence channels are prefixed `presence-` on the wire; the channel
 * defined as `request.{helpdeskRequest}` is addressed as
 * `presence-request.{id}` in the auth request.
 */

/*
 * The test suite's default broadcaster is `null` (phpunit.xml), which
 * short-circuits the auth handshake AND never loads the channel
 * authorization closures. To exercise the real authorization path we:
 *
 *   1. Point broadcasting at the `reverb` connection — the Pusher-protocol
 *      driver that actually runs the channel closure and signs (or rejects)
 *      the response. Dummy credentials are enough; nothing connects out.
 *   2. Re-require routes/channels.php so the closures register against this
 *      driver. (Under the null broadcaster the framework skips loading them,
 *      so the registry would otherwise be empty and every channel would 403.)
 */
beforeEach(function () {
    Config::set('broadcasting.default', 'reverb');
    Config::set('broadcasting.connections.reverb.key', 'test-key');
    Config::set('broadcasting.connections.reverb.secret', 'test-secret');
    Config::set('broadcasting.connections.reverb.app_id', 'test-app');

    require base_path('routes/channels.php');
});

test('a team member is authorized and receives their viewer payload', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id, 'name' => 'Sam Carter']);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    $request = Request::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($staff)->postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => "presence-request.{$request->id}",
    ]);

    $response->assertOk();

    // The presence payload is what every other viewer's banner renders from:
    // it must carry this user's id + name (the channel_data the closure
    // returned), embedded in the signed auth response.
    $channelData = json_decode($response->json('channel_data'), true);

    expect($channelData['user_id'])->toBe((string) $staff->id)
        ->and($channelData['user_info'])->toBe(['id' => $staff->id, 'name' => 'Sam Carter']);
});

test('a user from another team is denied the channel', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    // A request that belongs to a DIFFERENT installation entirely.
    $foreignRequest = Request::factory()->create();

    $this->actingAs($staff)->postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => "presence-request.{$foreignRequest->id}",
    ])->assertForbidden();
});

test('a guest cannot authorize the channel', function () {
    $request = Request::factory()->create();

    // No actingAs — the broadcasting guard rejects before the closure runs.
    $this->postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => "presence-request.{$request->id}",
    ])->assertForbidden();
});
