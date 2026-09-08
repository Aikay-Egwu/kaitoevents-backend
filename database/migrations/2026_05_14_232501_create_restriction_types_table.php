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
           Schema::create('restriction_types', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., "Open flames / naked candles"
            $table->string('slug')->unique(); // e.g., "open-flames"
            $table->text('description')->nullable(); // Optional help text for the user
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restriction_types');
    }
};
