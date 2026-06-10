<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->unique()->word(),
            'sla_first_response_minutes' => null,
        ];
    }

    /**
     * Give the category an SLA first-response target.
     */
    public function withSla(int $minutes = 60): static
    {
        return $this->state(fn (array $attributes) => [
            'sla_first_response_minutes' => $minutes,
        ]);
    }
}
