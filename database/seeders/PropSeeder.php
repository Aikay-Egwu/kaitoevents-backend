<?php

namespace Database\Seeders;

use App\Models\PropCategory;
use App\Models\Prop;
use Illuminate\Database\Seeder;

class PropSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create the Marquee Numbers category
        $marqueeCategory = PropCategory::create([
            'name' => 'Marquee Numbers',
            'slug' => 'marquee-numbers',
            'description' => 'Large illuminated marquee numbers perfect for events, weddings, and celebrations. These eye-catching numbers add a glamorous touch to any occasion.',
            'image' => 'categories/marquee-numbers.jpg',
            'is_active' => true,
        ]);

        // Create the number props
        $numbers = [
            [
                'name' => 'Number 1',
                'slug' => 'number-1',
                'description' => 'Large illuminated marquee number 1. Perfect for first birthdays, anniversaries, or any celebration where you want to highlight the number one.',
                'rental_price' => 25.00,
                'quantity_available' => 3,
                'quantity_total' => 3,
                'dimensions' => json_encode([
                    'width' => 60,
                    'height' => 120,
                    'depth' => 15,
                    'unit' => 'cm'
                ]),
                'weight' => 8.5,
                'setup_instructions' => 'Simply plug into standard power outlet. LED bulbs are pre-installed. Handle with care during transport.',
                'requires_setup' => false,
                'status' => 'active',
            ],
            [
                'name' => 'Number 2',
                'slug' => 'number-2',
                'description' => 'Large illuminated marquee number 2. Ideal for second birthdays, anniversaries, or any event celebrating the number two.',
                'rental_price' => 25.00,
                'quantity_available' => 3,
                'quantity_total' => 3,
                'dimensions' => json_encode([
                    'width' => 60,
                    'height' => 120,
                    'depth' => 15,
                    'unit' => 'cm'
                ]),
                'weight' => 8.5,
                'setup_instructions' => 'Simply plug into standard power outlet. LED bulbs are pre-installed. Handle with care during transport.',
                'requires_setup' => false,
                'status' => 'active',
            ],
            [
                'name' => 'Number 3',
                'slug' => 'number-3',
                'description' => 'Large illuminated marquee number 3. Great for third birthdays, anniversaries, or any celebration featuring the number three.',
                'rental_price' => 25.00,
                'quantity_available' => 3,
                'quantity_total' => 3,
                'dimensions' => json_encode([
                    'width' => 60,
                    'height' => 120,
                    'depth' => 15,
                    'unit' => 'cm'
                ]),
                'weight' => 8.5,
                'setup_instructions' => 'Simply plug into standard power outlet. LED bulbs are pre-installed. Handle with care during transport.',
                'requires_setup' => false,
                'status' => 'active',
            ],
        ];

        // Create each number prop
        foreach ($numbers as $numberData) {
            Prop::create(array_merge($numberData, [
                'prop_category_id' => $marqueeCategory->id,
            ]));
        }

        $this->command->info('PropSeeder completed successfully!');
        $this->command->info('Created 1 category: Marquee Numbers');
        $this->command->info('Created 3 props: Number 1, Number 2, Number 3');
    }
}