<?php

namespace Database\Factories;

use App\Enums\RequestSource;
use App\Models\Note;
use App\Models\Request;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Note>
 */
class NoteFactory extends Factory
{
    /**
     * Define the model's default state: a public reply authored by staff.
     *
     * The authorship invariant (user_id XOR customer_id) is enforced here
     * and in the states below — every path sets exactly one author and
     * nulls the other, since the schema deliberately has no CHECK
     * constraint.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'request_id' => Request::factory(),
            'user_id' => User::factory(),
            'customer_id' => null,
            'body' => fake()->paragraph(),
            'is_private' => false,
            'source' => RequestSource::Agent,
            'message_id' => null,
        ];
    }

    /**
     * Indicate the note is a private internal note (staff-only).
     *
     * PHP trivia taught in the tour doc: `private` is a reserved keyword,
     * but since PHP 7 keywords are allowed as *method* names, so this
     * reads naturally at the call site: Note::factory()->private().
     */
    public function private(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_private' => true,
        ]);
    }

    /**
     * Indicate the note was written by the customer, not staff.
     *
     * The customer_id closure resolves *after* request_id (array order),
     * so it can look up the request's own customer — keeping the timeline
     * coherent: a customer reply on a request is authored by the customer
     * who opened it.
     */
    public function fromCustomer(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
            'customer_id' => fn (array $resolved) => Request::query()
                ->whereKey($resolved['request_id'])
                ->firstOrFail()
                ->customer_id,
            'source' => RequestSource::Email,
            'is_private' => false,
        ]);
    }
}
