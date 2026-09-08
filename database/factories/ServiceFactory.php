<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Service>
 */
class ServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $services = [
            'Photography', 'Videography', 'Catering', 'Floral Design',
            'DJ Services', 'Live Band', 'Event Planning', 'Lighting',
            'Sound Equipment', 'Decoration', 'Security', 'Valet Parking',
            'Transportation', 'Makeup & Hair', 'MC / Host'
        ];

        return [
            'name' => $this->faker->unique()->randomElement($services),
            'description' => $this->faker->optional()->paragraph,
            'price' => $this->faker->randomFloat(2, 50, 5000),
        ];
    }
}
