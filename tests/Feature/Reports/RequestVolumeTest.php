<?php

use App\Models\Request;
use App\Models\Team;
use App\Queries\Reports\RequestVolume;
use Carbon\CarbonImmutable;

/**
 * RequestVolume buckets created/resolved requests into per-day counts over a
 * half-open window. Frozen clock + hand-placed timestamps give exact expected
 * numbers; the edge cases are the window boundaries and zero-filled empty days.
 */
beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 12:00:00'));
    $this->team = Team::factory()->create();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * Create a request on the test team with explicit created/resolved stamps.
 */
function volumeRequest(Team $team, string $createdAt, ?string $resolvedAt = null): Request
{
    return Request::factory()->create([
        'team_id' => $team->id,
        'created_at' => CarbonImmutable::parse($createdAt),
        'updated_at' => CarbonImmutable::parse($createdAt),
        'resolved_at' => $resolvedAt === null ? null : CarbonImmutable::parse($resolvedAt),
    ]);
}

test('counts created requests per day', function () {
    volumeRequest($this->team, '2026-06-08 09:00:00');
    volumeRequest($this->team, '2026-06-08 17:00:00');
    volumeRequest($this->team, '2026-06-09 10:00:00');

    $series = (new RequestVolume(
        $this->team,
        CarbonImmutable::parse('2026-06-08 00:00:00'),
        CarbonImmutable::parse('2026-06-10 00:00:00'),
    ))->perDay();

    expect($series['2026-06-08']['created'])->toBe(2)
        ->and($series['2026-06-09']['created'])->toBe(1);
});

test('counts resolved requests per day independently of when they were created', function () {
    // Created on the 8th, resolved on the 9th — counts created on 8th, resolved on 9th.
    volumeRequest($this->team, '2026-06-08 09:00:00', '2026-06-09 14:00:00');

    $series = (new RequestVolume(
        $this->team,
        CarbonImmutable::parse('2026-06-08 00:00:00'),
        CarbonImmutable::parse('2026-06-10 00:00:00'),
    ))->perDay();

    expect($series['2026-06-08'])->toBe(['created' => 1, 'resolved' => 0])
        ->and($series['2026-06-09'])->toBe(['created' => 0, 'resolved' => 1]);
});

test('zero-fills every day in the range, including empty ones', function () {
    volumeRequest($this->team, '2026-06-08 09:00:00');
    // nothing on the 9th
    volumeRequest($this->team, '2026-06-10 10:00:00');

    $series = (new RequestVolume(
        $this->team,
        CarbonImmutable::parse('2026-06-08 00:00:00'),
        CarbonImmutable::parse('2026-06-11 00:00:00'),
    ))->perDay();

    expect(array_keys($series))->toBe(['2026-06-08', '2026-06-09', '2026-06-10'])
        ->and($series['2026-06-09'])->toBe(['created' => 0, 'resolved' => 0]);
});

test('the from boundary is inclusive and the to boundary is exclusive', function () {
    // Exactly at `from` midnight → counted.
    volumeRequest($this->team, '2026-06-08 00:00:00');
    // Exactly at `to` midnight → excluded (half-open window).
    volumeRequest($this->team, '2026-06-10 00:00:00');

    $series = (new RequestVolume(
        $this->team,
        CarbonImmutable::parse('2026-06-08 00:00:00'),
        CarbonImmutable::parse('2026-06-10 00:00:00'),
    ))->perDay();

    expect(array_keys($series))->toBe(['2026-06-08', '2026-06-09'])
        ->and($series['2026-06-08']['created'])->toBe(1)
        // The 2026-06-10 request has no bucket — it's the exclusive end day.
        ->and($series)->not->toHaveKey('2026-06-10');
});

test('a late-night request lands in its own calendar day, not the next', function () {
    // 23:30 — the timezone-boundary fixture. Stored and bucketed in the app
    // timezone consistently, so it stays on the 8th.
    volumeRequest($this->team, '2026-06-08 23:30:00');

    $series = (new RequestVolume(
        $this->team,
        CarbonImmutable::parse('2026-06-08 00:00:00'),
        CarbonImmutable::parse('2026-06-10 00:00:00'),
    ))->perDay();

    expect($series['2026-06-08']['created'])->toBe(1)
        ->and($series['2026-06-09']['created'])->toBe(0);
});

test('only counts the queried team', function () {
    $other = Team::factory()->create();
    volumeRequest($this->team, '2026-06-08 09:00:00');
    volumeRequest($other, '2026-06-08 09:00:00');

    $series = (new RequestVolume(
        $this->team,
        CarbonImmutable::parse('2026-06-08 00:00:00'),
        CarbonImmutable::parse('2026-06-09 00:00:00'),
    ))->perDay();

    expect($series['2026-06-08']['created'])->toBe(1);
});

test('an empty range produces an empty series', function () {
    $series = (new RequestVolume(
        $this->team,
        CarbonImmutable::parse('2026-06-08 00:00:00'),
        CarbonImmutable::parse('2026-06-08 00:00:00'),
    ))->perDay();

    expect($series)->toBe([]);
});
