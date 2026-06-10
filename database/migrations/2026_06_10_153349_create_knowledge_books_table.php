<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Knowledge Books are the top level of the knowledge base hierarchy:
 * Book → Chapter → Page, mirroring HelpSpot's KB. A book is the
 * publishable unit customers browse on the portal ("Getting Started",
 * "Billing FAQ", ...).
 *
 * `is_published` gates portal visibility for the whole subtree: an
 * unpublished book hides ALL of its pages regardless of each page's own
 * state. `position` orders books on the index — assigned max+1 on create
 * and adjusted by simple swaps thereafter (see KnowledgeBook::boot()).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('knowledge_books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained();
            $table->string('name');

            // Slugs are unique per team, not globally — two installations
            // could both have a "getting-started" book. The composite
            // unique index backs the sluggable extraScope at the DB level.
            $table->string('slug');
            $table->unique(['team_id', 'slug']);

            $table->text('description')->nullable();
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('position');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_books');
    }
};
