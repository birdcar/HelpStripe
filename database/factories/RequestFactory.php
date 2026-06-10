<?php

namespace Database\Factories;

use App\Enums\RequestSource;
use App\Enums\RequestStatus;
use App\Models\Customer;
use App\Models\Request;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Request>
 */
class RequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `access_key` is intentionally absent: the model's `creating` event
     * generates it, and the factory must not pre-fill it or the event's
     * `empty()` guard would never exercise (and tests couldn't prove the
     * event works).
     *
     * The customer factory inherits the request's team via a closure so a
     * `Request::factory()->create()` produces a coherent single-team graph
     * instead of one team for the request and another for its customer.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'customer_id' => fn (array $attributes) => Customer::factory()->create([
                'team_id' => $attributes['team_id'],
            ])->id,
            'category_id' => null,
            'mailbox_id' => null,
            'assigned_to' => null,
            'subject' => fake()->sentence(6),
            'status' => RequestStatus::Active,
            'source' => fake()->randomElement(RequestSource::cases()),
            'is_urgent' => false,
            'first_responded_at' => null,
            'resolved_at' => null,
        ];
    }

    /**
     * Indicate the request is active (the default, made explicit).
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RequestStatus::Active,
            'resolved_at' => null,
        ]);
    }

    /**
     * Indicate the request has been resolved.
     */
    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RequestStatus::Resolved,
            'resolved_at' => now(),
        ]);
    }

    /**
     * Flag the request as urgent.
     */
    public function urgent(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_urgent' => true,
        ]);
    }

    /**
     * Backdate the request into an explicit window.
     *
     * Takes CarbonImmutable bounds rather than relying on `now()` inside
     * the state, so seeders/tests control the clock — DemoSeederTest
     * freezes time and this state stays deterministic.
     */
    public function aged(CarbonImmutable $from, CarbonImmutable $until): static
    {
        return $this->state(function (array $attributes) use ($from, $until) {
            $createdAt = CarbonImmutable::createFromTimestamp(
                fake()->numberBetween($from->getTimestamp(), $until->getTimestamp()),
                $from->getTimezone(),
            );

            return [
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        });
    }

    /**
     * Record a first response some minutes after the request was created.
     *
     * Pairs with Category::sla_first_response_minutes — pass a value inside
     * the category's target for an in-SLA request, outside it for a breach.
     */
    public function withFirstResponse(int $minutesAfterCreation = 30): static
    {
        return $this->state(function (array $attributes) use ($minutesAfterCreation) {
            $createdAt = CarbonImmutable::parse($attributes['created_at'] ?? now());

            return [
                'first_responded_at' => $createdAt->addMinutes($minutesAfterCreation),
            ];
        });
    }
}
