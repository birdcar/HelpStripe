<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pages are the leaves of the knowledge base: the actual articles. `body`
 * stores raw Markdown; rendering happens at display time with
 * Str::markdown(..., ['html_input' => 'escape']) so stored HTML can never
 * become live script on the portal (see docs/tour/05-knowledge-base.md).
 *
 * A page is portal-visible only when BOTH it and its book are published —
 * its own `is_published` is necessary but not sufficient.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chapter_id')->constrained()->cascadeOnDelete();

            // index() on title supports the portal's LIKE search; the
            // is_published index keeps published-only portal queries from
            // scanning draft rows.
            $table->string('title')->index();

            // Unique per chapter — the third level of the slug-scoping
            // lesson (per team → per book → per chapter).
            $table->string('slug');
            $table->unique(['chapter_id', 'slug']);

            $table->text('body');
            $table->boolean('is_published')->default(false)->index();
            $table->unsignedInteger('position');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
