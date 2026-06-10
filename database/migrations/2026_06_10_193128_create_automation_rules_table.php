<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One table for all three automation layers (mail, trigger, scheduled).
 *
 * The shapes are identical — a name, an ordering position, an active flag, and
 * two JSON blobs (conditions + actions) — so a single table backs all three.
 * The `layer` enum column is what the engine reads to decide *when* a rule
 * runs; `event` is only meaningful for trigger-layer rows (which domain event
 * fires them) and is nullable for the others. Taught as a one-table-vs-three
 * tradeoff in docs/tour/06-automation.md.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('team_id')->constrained();

            // RuleLayer enum, stored as a plain string (mail/trigger/scheduled).
            $table->string('layer');

            // Trigger layer only: which domain event fires this rule
            // (request_created / request_status_changed / note_added). Null for
            // mail and scheduled rules — they aren't event-driven.
            $table->string('event')->nullable();

            $table->string('name');

            // Inactive rules stay in the table (and the builder UI) but the
            // engine skips them — a soft on/off switch, not a delete.
            $table->boolean('is_active')->default(true);

            // Mail rules run in position order and later actions win on the
            // same field; the scheduled/trigger layers use it for stable
            // display ordering. Lower runs first.
            $table->unsignedInteger('position')->default(0);

            // The rule body: arrays of {field,operator,value} and
            // {action,value}. The model casts these to arrays, then hydrates
            // them into Condition/Action value objects (casts → VO mapping).
            $table->json('conditions');
            $table->json('actions');

            // Stamped by `automation:run` each time a scheduled rule is
            // evaluated — surfaces "when did this last run" in the UI and
            // makes a missed schedule visible.
            $table->timestamp('last_run_at')->nullable();

            $table->timestamps();

            // The engine's hottest query: "active rules for this team in this
            // layer, in order." The composite index covers the lookup;
            // ordering by position is a cheap filesort on the narrowed set.
            $table->index(['team_id', 'layer', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('automation_rules');
    }
};
