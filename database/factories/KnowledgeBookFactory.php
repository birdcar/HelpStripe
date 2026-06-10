<?php

namespace Database\Factories;

use App\Models\KnowledgeBook;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeBook>
 */
class KnowledgeBookFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Slug and position are intentionally absent: HasSlug derives the
     * slug from the name on create, and KnowledgeBook::boot() assigns
     * position max+1 within the team — the factory exercises the same
     * code paths production writes do.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'is_published' => false,
        ];
    }

    /**
     * Mark the book as visible on the public portal.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_published' => true,
        ]);
    }
}
