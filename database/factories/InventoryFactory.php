<?php

namespace Database\Factories;

use App\Models\InventoryCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Inventory>
 */
class InventoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $items = [
            'Round Table (8-person)',
            'Chiavari Chair',
            'LED Uplighting',
            'Crystal Centerpiece',
            'White Tablecloth',
            'Sound System',
            'Microphone',
            'Projector',
            'Dance Floor',
            'Tent 20x20',
            'Cocktail Table',
            'Bar Stool',
            'Lounge Chair',
            'Flower Arrangement',
            'Candelabra'
        ];

        $colors = ['White', 'Black', 'Gold', 'Silver', 'Clear', 'Blue', 'Red', 'Green', 'Multi-color'];
        $locations = ['Warehouse A', 'Warehouse B', 'Warehouse C', 'Storage Room 1', 'Storage Room 2'];

        return [
            'name' => $this->faker->randomElement($items),
            'inventory_category_id' => InventoryCategory::factory(),
            'price' => $this->faker->randomFloat(2, 5, 500),
            'cost_price' => $this->faker->randomFloat(2, 2, 300),
            'description' => $this->faker->sentence(10),
            'color' => $this->faker->randomElement($colors),
            'location' => $this->faker->randomElement($locations),
            'total_quantity' => $this->faker->numberBetween(5, 200),
            'quantity_available' => $this->faker->numberBetween(0, 100),
            'is_active' => $this->faker->boolean(85), // 85% chance of being active
        ];
    }

    /**
     * Indicate that the inventory item is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    /**
     * Indicate that the inventory item is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the inventory item is out of stock.
     */
    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity_available' => 0,
        ]);
    }

    /**
     * Indicate that the inventory item has low stock.
     */
    public function lowStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity_available' => $this->faker->numberBetween(1, 5),
        ]);
    }

    /**
     * Indicate that the inventory item is well stocked.
     */
    public function wellStocked(): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity_available' => $this->faker->numberBetween(20, 100),
        ]);
    }
}