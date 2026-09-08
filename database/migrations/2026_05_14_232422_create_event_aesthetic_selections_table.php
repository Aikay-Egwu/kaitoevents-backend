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
        Schema::create('event_aesthetic_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('aesthetic_dimension_id')->constrained()->cascadeOnDelete();

            // The specific direction for this event (the user input)
            $table->text('design_direction');

            $table->timestamps();

            // Ensures an event can't have duplicate entries for the same dimension
            $table->unique(['event_id', 'aesthetic_dimension_id'], 'event_dimension_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_aesthetic_selections');
    }
};
