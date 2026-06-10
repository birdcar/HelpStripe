<?php

namespace Database\Factories;

use App\Models\Mailbox;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mailbox>
 */
class MailboxFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * category_id defaults to null (an uncategorized mailbox); tests that
     * need a default category pass one explicitly or use a state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->words(2, true),
            'address' => fake()->unique()->safeEmail(),
            'category_id' => null,
        ];
    }
}
