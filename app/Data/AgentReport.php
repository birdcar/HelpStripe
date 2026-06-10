<?php

namespace App\Data;

/**
 * One row of the reporting page's "Agent performance" table. A readonly value
 * object (mirroring App\Data\UserTeam) so the collection type stays
 * `Collection<int, AgentReport>` and the view reads `$row->name`.
 *
 * `avgFirstResponseMinutes` is null when the agent answered nothing in range
 * — rendered "—". An idle agent still gets a row (zeros), driven by the team
 * roster rather than the request table.
 */
readonly class AgentReport
{
    public function __construct(
        public int $id,
        public string $name,
        public int $openAssigned,
        public int $resolvedInRange,
        public ?float $avgFirstResponseMinutes,
    ) {}
}
