<?php

namespace Database\Factories;

use App\Models\Filter;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Filter>
 */
class FilterFactory extends Factory
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
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'is_shared' => false,
            'criteria' => [],
        ];
    }

    /**
     * Indicate the filter is shared with the whole team.
     */
    public function shared(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_shared' => true,
        ]);
    }
}
