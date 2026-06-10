<?php

use App\Models\Category;
use App\Models\Request;
use App\Models\Team;
use App\Queries\Reports\CategoryPerformance;
use Carbon\CarbonImmutable;

/**
 * CategoryPerformance is the SLA report. Frozen clock + hand-built requests
 * give exact averages and breach counts. The interesting cases: overdue
 * (unanswered, past target) counting as a breach, the boundary case, and the
 * no-SLA category being present-but-excluded from breach math.
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
 * Create a request in a category at a fixed time, optionally answered N
 * minutes later.
 */
function categoryRequest(Category $category, string $createdAt, ?int $firstResponseMinutes = null): Request
{
    $created = CarbonImmutable::parse($createdAt);

    return Request::factory()->create([
        'team_id' => $category->team_id,
        'category_id' => $category->id,
        'created_at' => $created,
        'updated_at' => $created,
        'first_responded_at' => $firstResponseMinutes === null ? null : $created->addMinutes($firstResponseMinutes),
    ]);
}

test('averages first response minutes over answered requests in range', function () {
    $billing = Category::factory()->create(['team_id' => $this->team->id, 'sla_first_response_minutes' => 60]);

    // 30m + 90m → avg 60.
    categoryRequest($billing, '2026-06-08 09:00:00', firstResponseMinutes: 30);
    categoryRequest($billing, '2026-06-08 10:00:00', firstResponseMinutes: 90);

    $row = (new CategoryPerformance($this->team, $this->from, $this->to))->rows()
        ->firstWhere('id', $billing->id);

    expect($row->count)->toBe(2)
        ->and($row->avgFirstResponseMinutes)->toBe(60.0)
        ->and($row->slaTargetMinutes)->toBe(60);
});

test('counts a late-answered request as a breach', function () {
    $billing = Category::factory()->create(['team_id' => $this->team->id, 'sla_first_response_minutes' => 60]);

    categoryRequest($billing, '2026-06-08 09:00:00', firstResponseMinutes: 30);  // in SLA
    categoryRequest($billing, '2026-06-08 10:00:00', firstResponseMinutes: 90);  // breach

    $row = (new CategoryPerformance($this->team, $this->from, $this->to))->rows()
        ->firstWhere('id', $billing->id);

    expect($row->breached)->toBe(1);
});

test('a response landing exactly on the target is not a breach', function () {
    $billing = Category::factory()->create(['team_id' => $this->team->id, 'sla_first_response_minutes' => 60]);

    categoryRequest($billing, '2026-06-08 09:00:00', firstResponseMinutes: 60);

    $row = (new CategoryPerformance($this->team, $this->from, $this->to))->rows()
        ->firstWhere('id', $billing->id);

    expect($row->breached)->toBe(0);
});

test('an overdue unanswered request in range counts as a breach', function () {
    $billing = Category::factory()->create(['team_id' => $this->team->id, 'sla_first_response_minutes' => 60]);

    // Created within range, still unanswered, well past the 60m target by now.
    categoryRequest($billing, '2026-06-09 09:00:00', firstResponseMinutes: null);

    $row = (new CategoryPerformance($this->team, $this->from, $this->to))->rows()
        ->firstWhere('id', $billing->id);

    expect($row->count)->toBe(1)
        ->and($row->breached)->toBe(1)
        // No answered samples → average is null, not 0.
        ->and($row->avgFirstResponseMinutes)->toBeNull();
});

test('a category with all-unanswered requests reports a null average', function () {
    $billing = Category::factory()->create(['team_id' => $this->team->id, 'sla_first_response_minutes' => 60]);

    categoryRequest($billing, '2026-06-09 09:00:00', firstResponseMinutes: null);
    categoryRequest($billing, '2026-06-09 10:00:00', firstResponseMinutes: null);

    $row = (new CategoryPerformance($this->team, $this->from, $this->to))->rows()
        ->firstWhere('id', $billing->id);

    expect($row->avgFirstResponseMinutes)->toBeNull();
});

test('a category with no SLA target is present but never breaches', function () {
    $sales = Category::factory()->create(['team_id' => $this->team->id, 'sla_first_response_minutes' => null]);

    // Answered 5 hours late, and one unanswered for days — neither breaches.
    categoryRequest($sales, '2026-06-08 09:00:00', firstResponseMinutes: 300);
    categoryRequest($sales, '2026-06-08 10:00:00', firstResponseMinutes: null);

    $row = (new CategoryPerformance($this->team, $this->from, $this->to))->rows()
        ->firstWhere('id', $sales->id);

    expect($row)->not->toBeNull()
        ->and($row->count)->toBe(2)
        ->and($row->slaTargetMinutes)->toBeNull()
        ->and($row->breached)->toBe(0)
        ->and($row->avgFirstResponseMinutes)->toBe(300.0);
});

test('only counts requests created inside the range', function () {
    $billing = Category::factory()->create(['team_id' => $this->team->id, 'sla_first_response_minutes' => 60]);

    categoryRequest($billing, '2026-06-08 09:00:00', firstResponseMinutes: 30); // in range
    categoryRequest($billing, '2026-06-01 09:00:00', firstResponseMinutes: 30); // before range

    $row = (new CategoryPerformance($this->team, $this->from, $this->to))->rows()
        ->firstWhere('id', $billing->id);

    expect($row->count)->toBe(1);
});

test('returns one row per category in the team, ordered by name', function () {
    Category::factory()->create(['team_id' => $this->team->id, 'name' => 'Technical Support']);
    Category::factory()->create(['team_id' => $this->team->id, 'name' => 'Billing']);

    $rows = (new CategoryPerformance($this->team, $this->from, $this->to))->rows();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('name')->all())->toBe(['Billing', 'Technical Support']);
});
