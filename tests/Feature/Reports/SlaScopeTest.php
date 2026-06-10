<?php

use App\Models\Category;
use App\Models\Request;
use App\Models\Team;
use Carbon\CarbonImmutable;

/**
 * scopeSlaBreached / scopeSlaOverdue are the SHARED definition of an SLA
 * breach — Phase 8 reports and Phase 6 automation both lean on them. These
 * tests pin the exact semantics: late-answered, overdue-unanswered, the
 * boundary case (exactly on target is NOT a breach), and the exclusions
 * (no category, no SLA target).
 */

/**
 * Build a request created a fixed number of minutes ago, optionally answered
 * a fixed number of minutes after creation.
 */
function slaRequest(Category $category, int $createdMinutesAgo, ?int $firstResponseMinutes = null): Request
{
    $createdAt = CarbonImmutable::now()->subMinutes($createdMinutesAgo);

    return Request::factory()->create([
        'team_id' => $category->team_id,
        'category_id' => $category->id,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
        'first_responded_at' => $firstResponseMinutes === null
            ? null
            : $createdAt->addMinutes($firstResponseMinutes),
    ]);
}

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 12:00:00'));
    $this->team = Team::factory()->create();
    $this->billing = Category::factory()->create([
        'team_id' => $this->team->id,
        'sla_first_response_minutes' => 60,
    ]);
    $this->noSla = Category::factory()->create([
        'team_id' => $this->team->id,
        'sla_first_response_minutes' => null,
    ]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('a request answered after its SLA target is breached', function () {
    slaRequest($this->billing, createdMinutesAgo: 120, firstResponseMinutes: 90);

    expect(Request::query()->slaBreached()->count())->toBe(1);
});

test('a request answered within its SLA target is not breached', function () {
    slaRequest($this->billing, createdMinutesAgo: 120, firstResponseMinutes: 30);

    expect(Request::query()->slaBreached()->count())->toBe(0);
});

test('a response landing exactly on the target minute is not a breach', function () {
    // 60-minute target, answered at exactly 60 minutes → in-SLA (> not >=).
    slaRequest($this->billing, createdMinutesAgo: 120, firstResponseMinutes: 60);

    expect(Request::query()->slaBreached()->count())->toBe(0);
});

test('an unanswered request past its target is breached and overdue', function () {
    // Created 90 minutes ago, still no response, 60-minute target → overdue.
    slaRequest($this->billing, createdMinutesAgo: 90, firstResponseMinutes: null);

    expect(Request::query()->slaBreached()->count())->toBe(1)
        ->and(Request::query()->slaOverdue()->count())->toBe(1);
});

test('an unanswered request still inside its target is neither breached nor overdue', function () {
    slaRequest($this->billing, createdMinutesAgo: 30, firstResponseMinutes: null);

    expect(Request::query()->slaBreached()->count())->toBe(0)
        ->and(Request::query()->slaOverdue()->count())->toBe(0);
});

test('an answered-late request is breached but not overdue', function () {
    // Overdue is the unanswered subset only.
    slaRequest($this->billing, createdMinutesAgo: 300, firstResponseMinutes: 200);

    expect(Request::query()->slaBreached()->count())->toBe(1)
        ->and(Request::query()->slaOverdue()->count())->toBe(0);
});

test('requests in a category with no SLA target never breach', function () {
    slaRequest($this->noSla, createdMinutesAgo: 5000, firstResponseMinutes: 4000);
    slaRequest($this->noSla, createdMinutesAgo: 5000, firstResponseMinutes: null);

    expect(Request::query()->slaBreached()->count())->toBe(0)
        ->and(Request::query()->slaOverdue()->count())->toBe(0);
});

test('requests with no category never breach', function () {
    $createdAt = CarbonImmutable::now()->subMinutes(5000);
    Request::factory()->create([
        'team_id' => $this->team->id,
        'category_id' => null,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
        'first_responded_at' => null,
    ]);

    expect(Request::query()->slaBreached()->count())->toBe(0)
        ->and(Request::query()->slaOverdue()->count())->toBe(0);
});
