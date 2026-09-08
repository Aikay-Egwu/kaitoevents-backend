<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('event_design_concepts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            // Concept Name & Title
            $table->string('concept_name')->nullable();
            $table->string('design_direction')->nullable(); // The single sentence
            $table->text('concept_narrative')->nullable();

            // Colour Palette
            // JSON structure: {"primary": "", "secondary": "", "accent": "", "metal": "", "neutral": "", "avoid": ""}

            // References// URL or file path
            $table->text('key_reference_notes')->nullable();

            // Aesthetic Direction
            // JSON structure: {"overall_style": "", "texture": "", "floral_style": "", "lighting_mood": "", "formality": "", "guest_feeling": ""}
            //$table->json('aesthetic_direction')->nullable(); 

            $table->timestamps();
        });

        

        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_design_concepts');
    }
};
