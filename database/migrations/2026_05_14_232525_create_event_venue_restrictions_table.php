<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('event_venue_restrictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restriction_type_id')->constrained()->cascadeOnDelete();
            
            // The two specific columns from the form
            $table->string('venue_position')->nullable(); 
            $table->text('impact_on_design')->nullable();

            $table->timestamps();

            // Prevent duplicate entries for the same restriction on one event
            $table->unique(['event_id', 'restriction_type_id'], 'event_restriction_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_venue_restrictions');
    }
};
