<?php

use App\Enums\RequestStatus;
use App\Enums\TeamRole;
use App\Models\Category;
use App\Models\Request;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Livewire\Livewire;

/**
 * The reports page: permission gating (route + nav), rendering all four
 * blocks, and range switching changing the numbers consistently (30d ⊇ 7d).
 */

/**
 * Create a staff member on a fresh team, optionally holding the Administrator
 * role (which carries 'view reports').
 *
 * @return array{0: User, 1: Team}
 */
function reportsStaffer(bool $administrator = true): array
{
    test()->seed(PermissionSeeder::class);

    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);

    $user->assignRole($administrator ? 'Administrator' : 'Help Desk Staff');

    return [$user, $team];
}

test('staff with view reports permission can open the page', function () {
    [$user, $team] = reportsStaffer(administrator: true);

    $this->actingAs($user)
        ->get(route('reports.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertSee('Reports');
});

test('staff without view reports permission are forbidden', function () {
    [$user, $team] = reportsStaffer(administrator: false);

    $this->actingAs($user)
        ->get(route('reports.index', ['current_team' => $team->slug]))
        ->assertForbidden();
});

test('the reports nav item is hidden from staff without the permission', function () {
    [$user, $team] = reportsStaffer(administrator: false);

    // The dashboard renders the sidebar; the gated item must not appear.
    $response = $this->actingAs($user)->get(route('dashboard', ['current_team' => $team->slug]));

    $response->assertOk()
        ->assertDontSee(route('reports.index', ['current_team' => $team->slug]));
});

test('the reports nav item is shown to staff with the permission', function () {
    [$user, $team] = reportsStaffer(administrator: true);

    $this->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertSee(route('reports.index', ['current_team' => $team->slug]));
});

test('the page renders all four reporting blocks', function () {
    [$user, $team] = reportsStaffer();
    Category::factory()->create(['team_id' => $team->id, 'name' => 'Billing', 'sla_first_response_minutes' => 60]);

    $this->actingAs($user);

    Livewire::test('pages::reports.index')
        ->assertSee('Open')
        ->assertSee('Requests over time')
        ->assertSee('Requests by category')
        ->assertSee('Agent performance')
        ->assertSee('Billing');
});

test('switching the range is URL-bound and re-scopes the data', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 12:00:00'));
    [$user, $team] = reportsStaffer();

    // One request created 5 days ago (in both 7d and 30d windows), one created
    // 20 days ago (in 30d only).
    Request::factory()->create([
        'team_id' => $team->id,
        'created_at' => CarbonImmutable::now()->subDays(5),
        'updated_at' => CarbonImmutable::now()->subDays(5),
    ]);
    Request::factory()->create([
        'team_id' => $team->id,
        'created_at' => CarbonImmutable::now()->subDays(20),
        'updated_at' => CarbonImmutable::now()->subDays(20),
    ]);

    $this->actingAs($user);

    $createdInWindow = fn (array $volume): int => array_sum(array_column($volume, 'created'));

    $component = Livewire::test('pages::reports.index')->set('range', 7);
    expect($createdInWindow($component->get('volume')))->toBe(1);

    $component->set('range', 30);
    expect($createdInWindow($component->get('volume')))->toBe(2);

    CarbonImmutable::setTestNow();
});

test('the breached stat card is driven by the shared SLA scope', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 12:00:00'));
    [$user, $team] = reportsStaffer();
    $billing = Category::factory()->create(['team_id' => $team->id, 'sla_first_response_minutes' => 60]);

    // Overdue unanswered → a breach.
    Request::factory()->create([
        'team_id' => $team->id,
        'category_id' => $billing->id,
        'status' => RequestStatus::Active,
        'created_at' => CarbonImmutable::now()->subMinutes(120),
        'updated_at' => CarbonImmutable::now()->subMinutes(120),
        'first_responded_at' => null,
    ]);

    $this->actingAs($user);

    $snapshot = Livewire::test('pages::reports.index')->get('snapshot');

    expect($snapshot['breached'])->toBe(1)
        ->and($snapshot['overdue'])->toBe(1);

    CarbonImmutable::setTestNow();
});
