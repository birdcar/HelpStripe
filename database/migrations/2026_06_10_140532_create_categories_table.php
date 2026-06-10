<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categories group requests for routing and reporting (Billing, Technical
 * Support, ...). A category may carry an SLA target: the number of minutes
 * within which a first response is expected. Phase 8 reporting compares
 * `requests.first_responded_at` against this target.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained();
            $table->string('name');

            // nullable() means "no SLA for this category" — e.g. Sales.
            // unsignedInteger fits minutes comfortably (no negative SLAs).
            $table->unsignedInteger('sla_first_response_minutes')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
