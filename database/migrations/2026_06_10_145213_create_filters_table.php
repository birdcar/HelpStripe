<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved Filters — HelpSpot's name for saved queue views.
 *
 * A Filter is nothing more than a named bag of queue criteria stored as
 * JSON. The queue page builds its WHERE clauses from the exact same
 * criteria array whether it came from the URL or from a saved Filter —
 * that equivalence is the whole design.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('filters', function (Blueprint $table) {
            $table->id();

            $table->foreignId('team_id')->constrained();

            // The owner. Private filters are visible only to this user;
            // shared ones to the whole team.
            $table->foreignId('user_id')->constrained();

            $table->string('name');
            $table->boolean('is_shared')->default(false);

            // {status, category_id, assignee, urgent, search} — a JSON
            // column instead of five nullable columns because the criteria
            // vocabulary grows in later phases and unknown keys must be
            // ignorable, not schema migrations.
            $table->json('criteria');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('filters');
    }
};
