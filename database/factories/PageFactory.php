<?php

namespace Database\Factories;

use App\Models\Chapter;
use App\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The body is plausible Markdown (heading + paragraphs) because pages
     * render through Str::markdown() — fixtures should look like what the
     * renderer will actually receive. Slug and position derive on create.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chapter_id' => Chapter::factory(),
            'title' => fake()->unique()->sentence(4),
            'body' => '## '.fake()->sentence(3)."\n\n"
                .fake()->paragraph()."\n\n"
                .'- '.fake()->sentence()."\n"
                .'- '.fake()->sentence(),
            'is_published' => false,
        ];
    }

    /**
     * Mark the page as publishable on the portal (still subject to the
     * book's own published flag).
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_published' => true,
        ]);
    }
}
