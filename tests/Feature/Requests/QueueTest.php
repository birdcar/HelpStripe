<?php

use App\Enums\RequestStatus;
use App\Enums\TeamRole;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Request;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $team = Team::factory()->create();

    $this->get(route('requests.index', ['current_team' => $team->slug]))
        ->assertRedirect(route('login'));
});

test('the queue page renders for a team member over http', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    Request::factory()->create(['team_id' => $team->id, 'subject' => 'Printer is haunted']);

    $this->actingAs($staff)
        ->get(route('requests.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertSee('Printer is haunted');
});

test('the queue lists only the current team requests', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    Request::factory()->create(['team_id' => $team->id, 'subject' => 'Ours: broken login']);
    Request::factory()->create(['subject' => 'Theirs: other team secret']);

    $this->actingAs($staff);

    Livewire::test('pages::requests.index')
        ->assertSee('Ours: broken login')
        ->assertDontSee('Theirs: other team secret');
});

test('filtering by status narrows the queue', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    Request::factory()->create(['team_id' => $team->id, 'subject' => 'Active one', 'status' => RequestStatus::Active]);
    Request::factory()->resolved()->create(['team_id' => $team->id, 'subject' => 'Resolved one']);

    $this->actingAs($staff);

    Livewire::test('pages::requests.index')
        ->set('status', 'resolved')
        ->assertSee('Resolved one')
        ->assertDontSee('Active one');
});

test('an invalid status value is ignored rather than crashing', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    Request::factory()->create(['team_id' => $team->id, 'subject' => 'Still visible']);

    $this->actingAs($staff);

    Livewire::test('pages::requests.index')
        ->set('status', 'banana')
        ->assertSee('Still visible');
});

test('filtering by category narrows the queue', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    $billing = Category::factory()->create(['team_id' => $team->id, 'name' => 'Billing']);
    $sales = Category::factory()->create(['team_id' => $team->id, 'name' => 'Sales']);

    Request::factory()->create(['team_id' => $team->id, 'subject' => 'Billing question', 'category_id' => $billing->id]);
    Request::factory()->create(['team_id' => $team->id, 'subject' => 'Sales question', 'category_id' => $sales->id]);

    $this->actingAs($staff);

    Livewire::test('pages::requests.index')
        ->set('category', (string) $billing->id)
        ->assertSee('Billing question')
        ->assertDontSee('Sales question');
});

test('the assignee filter supports me unassigned and specific staff', function () {
    $team = Team::factory()->create();
    $me = User::factory()->create(['current_team_id' => $team->id]);
    $teammate = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($me, ['role' => TeamRole::Member->value]);
    $team->members()->attach($teammate, ['role' => TeamRole::Member->value]);

    Request::factory()->create(['team_id' => $team->id, 'subject' => 'Mine', 'assigned_to' => $me->id]);
    Request::factory()->create(['team_id' => $team->id, 'subject' => 'Theirs', 'assigned_to' => $teammate->id]);
    Request::factory()->create(['team_id' => $team->id, 'subject' => 'Nobody owns this', 'assigned_to' => null]);

    $this->actingAs($me);

    Livewire::test('pages::requests.index')
        ->set('assignee', 'me')
        ->assertSee('Mine')
        ->assertDontSee('Theirs')
        ->assertDontSee('Nobody owns this')
        ->set('assignee', 'unassigned')
        ->assertSee('Nobody owns this')
        ->assertDontSee('Mine')
        ->set('assignee', (string) $teammate->id)
        ->assertSee('Theirs')
        ->assertDontSee('Mine');
});

test('the urgent toggle shows urgent requests only', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    Request::factory()->urgent()->create(['team_id' => $team->id, 'subject' => 'Everything is on fire']);
    Request::factory()->create(['team_id' => $team->id, 'subject' => 'Mild inconvenience']);

    $this->actingAs($staff);

    Livewire::test('pages::requests.index')
        ->set('urgent', true)
        ->assertSee('Everything is on fire')
        ->assertDontSee('Mild inconvenience');
});

test('search matches subject substrings and customer email', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    $customer = Customer::factory()->create(['team_id' => $team->id, 'email' => 'maria@example.com']);

    Request::factory()->create(['team_id' => $team->id, 'subject' => 'Invoice discrepancy', 'customer_id' => $customer->id]);
    Request::factory()->create(['team_id' => $team->id, 'subject' => 'Unrelated topic']);

    $this->actingAs($staff);

    Livewire::test('pages::requests.index')
        ->set('search', 'discrep')
        ->assertSee('Invoice discrepancy')
        ->assertDontSee('Unrelated topic')
        ->set('search', 'maria@')
        ->assertSee('Invoice discrepancy')
        ->assertDontSee('Unrelated topic');
});

test('combined criteria intersect: unassigned and urgent', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    Request::factory()->urgent()->create(['team_id' => $team->id, 'subject' => 'Urgent and unassigned', 'assigned_to' => null]);
    Request::factory()->urgent()->create(['team_id' => $team->id, 'subject' => 'Urgent but owned', 'assigned_to' => $staff->id]);
    Request::factory()->create(['team_id' => $team->id, 'subject' => 'Unassigned but calm', 'assigned_to' => null]);

    $this->actingAs($staff);

    Livewire::test('pages::requests.index')
        ->set('assignee', 'unassigned')
        ->set('urgent', true)
        ->assertSee('Urgent and unassigned')
        ->assertDontSee('Urgent but owned')
        ->assertDontSee('Unassigned but calm');
});

test('the queue paginates at fifteen rows', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    // 16 requests with descending recency: subject 01 is newest, so
    // subject 16 is the lone row on page 2. Zero-padded so "subject 01"
    // can never substring-match inside "subject 16".
    foreach (range(1, 16) as $i) {
        $padded = str_pad((string) $i, 2, '0', STR_PAD_LEFT);

        Request::factory()->create([
            'team_id' => $team->id,
            'subject' => "Distinct subject {$padded}",
            'created_at' => now()->subMinutes($i),
            'updated_at' => now()->subMinutes($i),
        ]);
    }

    $this->actingAs($staff);

    Livewire::test('pages::requests.index')
        ->assertSee('Distinct subject 01')
        ->assertDontSee('Distinct subject 16')
        ->call('setPage', 2)
        ->assertSee('Distinct subject 16')
        ->assertDontSee('Distinct subject 01');
});

test('changing a filter resets pagination to page one', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    Request::factory()->count(16)->create(['team_id' => $team->id]);
    Request::factory()->urgent()->create(['team_id' => $team->id, 'subject' => 'The urgent one']);

    $this->actingAs($staff);

    Livewire::test('pages::requests.index')
        ->call('setPage', 2)
        ->set('urgent', true)
        ->assertSee('The urgent one');
});

test('an empty result set renders the empty state', function () {
    $team = Team::factory()->create();
    $staff = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);

    $this->actingAs($staff);

    Livewire::test('pages::requests.index')
        ->assertSee('No requests match the current filters.');
});
