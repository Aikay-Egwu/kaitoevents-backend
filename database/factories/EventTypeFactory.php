<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EventType>
 */
class EventTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $titles = [
            'Wedding', 'Corporate', 'Birthday', 'Conference', 'Product Launch',
            'Anniversary', 'Graduation', 'Cocktail Party', 'Charity Gala',
            'Trade Show', 'Workshop', 'Networking Event'
        ];
        $title = $this->faker->unique()->randomElement($titles);

        return [
            'title' => $title,
            'slug' => Str::slug($title),
            'tag' => $this->faker->optional()->word,
            'description' => $this->faker->optional()->sentence(8),
            'parent_id' => null,
            'image' => $this->faker->optional()->imageUrl(640, 480, 'events'),
            'status' => $this->faker->randomElement(['active', 'inactive']),
        ];
    }
}
