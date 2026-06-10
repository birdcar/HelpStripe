<?php

namespace Database\Factories;

use App\Models\Chapter;
use App\Models\KnowledgeBook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Chapter>
 */
class ChapterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Slug and position are derived on create (HasSlug + Chapter::boot()),
     * so the factory only supplies the source fields.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'knowledge_book_id' => KnowledgeBook::factory(),
            'name' => fake()->unique()->words(3, true),
        ];
    }
}
