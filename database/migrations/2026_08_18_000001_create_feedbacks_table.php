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
        Schema::create('feedbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();

            // Header details supplied by the client
            $table->string('client_name');
            $table->date('event_date');
            $table->foreignId('event_type_id')
                ->nullable()
                ->constrained('event_types')
                ->nullOnDelete();

            // Part 1 - Core Values (rated 1-6)
            $table->unsignedTinyInteger('creativity');
            $table->unsignedTinyInteger('excellence');
            $table->unsignedTinyInteger('transcendence');
            $table->unsignedTinyInteger('bespoke');
            $table->unsignedTinyInteger('integrity');
            $table->unsignedTinyInteger('exactitude');
            $table->unsignedTinyInteger('intentionality');
            $table->unsignedTinyInteger('genuine_connection');

            // Part 2 - The Process
            $table->unsignedTinyInteger('communication_rating');
            $table->enum('experience_comparison', [
                'below_expectations',
                'met_expectations',
                'exceeded_expectations',
            ]);
            $table->boolean('likely_to_return');
            $table->boolean('would_recommend');

            // Part 3 - Summary
            $table->text('unmet_expectations')->nullable();
            $table->text('stood_out')->nullable();
            // Admin triage status
            $table->string('status')->default('new');
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feedbacks');
    }
};
