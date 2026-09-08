<?php

namespace Database\Seeders;

use App\Models\RestrictionType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * RestrictionTypeSeeder — Seeds the predefined venue restriction categories.
 *
 * These are the standard restrictions that event planners check for during
 * a venue walkthrough. Each has a unique slug for API lookups.
 */
class RestrictionTypeSeeder extends Seeder
{
    public function run(): void
    {
        $restrictions = [
            [
                'name' => 'Open flames / naked candles',
                'slug' => 'open-flames',
                'description' => 'Venue does not permit open flames, candles, or any naked fire sources.',
            ],
            [
                'name' => 'Fixing to walls / ceilings',
                'slug' => 'fixing-to-walls',
                'description' => 'Venue prohibits or restricts fixing decorations, rigging, or fixtures to walls and ceilings.',
            ],
            [
                'name' => 'Floral / Botanical',
                'slug' => 'floral-botanical',
                'description' => 'Restrictions on floral arrangements, live plants, or botanical materials.',
            ],
            [
                'name' => 'Confetti / glitter / loose petals',
                'slug' => 'confetti-glitter',
                'description' => 'Venue does not allow confetti, glitter, loose petals, or similar scattered materials.',
            ],
            [
                'name' => 'Catering',
                'slug' => 'catering',
                'description' => 'Restrictions on external catering, food preparation, or serving requirements.',
            ],
        ];

        foreach ($restrictions as $restriction) {
            RestrictionType::updateOrCreate(
                ['slug' => $restriction['slug']],
                $restriction
            );
        }

        $this->command?->info('Restriction types seeded successfully.');
    }
}
