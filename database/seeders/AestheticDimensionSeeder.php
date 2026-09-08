<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AestheticDimension;

class AestheticDimensionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $dimensions = [
            ['name' => 'Floral Style', 'placeholder' => 'e.g. Garden style / Architectural', 'display_order' => 1],
            ['name' => 'Tabletop Style', 'placeholder' => 'e.g. Lush & romantic / Minimalist geometric', 'display_order' => 2],
            ['name' => 'Lighting Ambiance', 'placeholder' => 'e.g. Soft candle glow / Dramatic spotlights', 'display_order' => 3],
            ['name' => 'Linens & Drapery', 'placeholder' => 'e.g. Rich velvet / Sheer airy fabrics', 'display_order' => 4],
            ['name' => 'Color Palette', 'placeholder' => 'e.g. Muted pastels / Deep jewel tones', 'display_order' => 5],
            ['name' => 'Furniture Style', 'placeholder' => 'e.g. Vintage plush / Modern modular', 'display_order' => 6],
            ['name' => 'Overall Mood', 'placeholder' => 'e.g. Festive and energetic / Intimate and cozy', 'display_order' => 7],
        ];

        foreach ($dimensions as $dimension) {
            AestheticDimension::create($dimension);
        }
    }
}
