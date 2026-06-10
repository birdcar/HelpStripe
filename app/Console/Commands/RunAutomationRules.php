<?php

namespace App\Console\Commands;

use App\Enums\RequestStatus;
use App\Enums\RuleLayer;
use App\Models\AutomationRule;
use App\Models\Request;
use App\Support\Automation\RuleEngine;
use Illuminate\Console\Command;

/**
 * Evaluate the scheduled-layer automation rules against the open queue.
 *
 * The time-based layer: where mail rules run at intake and triggers run on
 * events, these rules run on a timer (every five minutes, see
 * routes/console.php). For each active scheduled rule, the command scans the
 * team's open (non-closed) requests, applies the rule's actions to matches via
 * the engine, and stamps `last_run_at`.
 *
 * Idempotence is the rule author's job, by condition design: the seeded
 * "unanswered 24h AND not urgent → set urgent" can't re-match once urgent, so a
 * second run no-ops. A rule with a non-self-extinguishing condition WILL re-fire
 * every run — a documented footgun, warned about in the builder UI.
 *
 * The whole-queue scan is O(open requests × rules) — fine at demo scale, named
 * as a scaling gap in the tour doc.
 *
 *     php artisan automation:run
 */
class RunAutomationRules extends Command
{
    protected $signature = 'automation:run';

    protected $description = 'Evaluate scheduled automation rules against the open request queue';

    public function handle(RuleEngine $engine): int
    {
        $rules = AutomationRule::query()
            ->where('layer', RuleLayer::Scheduled->value)
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $totalFired = 0;

        foreach ($rules as $rule) {
            // Scan only the open (active/pending) queue for this rule's team —
            // closed and resolved requests are done; automation leaves them be.
            $requests = Request::query()
                ->where('team_id', $rule->team_id)
                ->whereIn('status', array_map(
                    fn (RequestStatus $status) => $status->value,
                    RequestStatus::open(),
                ))
                ->get();

            foreach ($requests as $request) {
                if ($engine->runRule($rule, $request)) {
                    $totalFired++;
                }
            }

            // Stamp regardless of matches — "we evaluated this rule at T" is the
            // useful signal; a long gap since last_run_at means the scheduler
            // stopped ticking.
            $rule->forceFill(['last_run_at' => now()])->save();
        }

        $this->info("Evaluated {$rules->count()} scheduled rule(s); applied {$totalFired} action set(s).");

        return self::SUCCESS;
    }
}
