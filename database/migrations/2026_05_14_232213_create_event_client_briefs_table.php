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
        Schema::create('event_client_briefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            
            // Who the Client Is
            $table->text('client_background')->nullable();
            $table->text('occasion_meaning')->nullable();
            
            // What They Want
            $table->text('client_words_brief')->nullable(); // The brief in their own words
            $table->text('non_negotiables')->nullable();
            $table->text('what_to_avoid')->nullable();
            
            // Cultural, Religious & Personal Considerations
            $table->text('cultural_religious_requirements')->nullable();
            $table->text('personal_significance_motifs')->nullable();
            
            // Budget & Investment
            // Using 12,2 allows for budgets up to 9,999,999,999.99
            $table->decimal('agreed_total_budget', 12, 2)->nullable();
            $table->decimal('kaito_events_fee', 12, 2)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_client_briefs');
    }
};
