<?php

namespace App\Data;

/**
 * One row of the reporting page's "Requests by category" table — the SLA
 * report. A readonly value object (mirroring App\Data\UserTeam) rather than
 * a loose array: the view reads `$row->name` instead of `$row['name']`, and
 * the collection type stays `Collection<int, CategoryReport>`, which PHPStan
 * handles cleanly (array shapes in a collection's value position are
 * invariant and trip the analyzer; a named class doesn't).
 *
 * `avgFirstResponseMinutes` is null when the category had no answered
 * requests in range — the view renders "—", never a misleading 0.
 * `slaTargetMinutes` is null for a category with no SLA, whose `breached`
 * count is therefore always 0.
 */
readonly class CategoryReport
{
    public function __construct(
        public int $id,
        public string $name,
        public int $count,
        public ?float $avgFirstResponseMinutes,
        public ?int $slaTargetMinutes,
        public int $breached,
    ) {}
}
