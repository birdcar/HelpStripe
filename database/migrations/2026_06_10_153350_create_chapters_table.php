<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chapters are the middle of the Book → Chapter → Page hierarchy. They
 * carry no published flag of their own — visibility is decided by the
 * book above and the page below; a chapter is just a labeled, ordered
 * grouping.
 *
 * `cascadeOnDelete` means removing a chapter removes its pages too — the
 * database enforces the hierarchy so application code never has to clean
 * up orphans.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_book_id')->constrained()->cascadeOnDelete();
            $table->string('name');

            // Unique per book: two books can both open with an
            // "introduction" chapter. See the knowledge_books migration
            // for the slug-scoping rationale.
            $table->string('slug');
            $table->unique(['knowledge_book_id', 'slug']);

            $table->unsignedInteger('position');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chapters');
    }
};
