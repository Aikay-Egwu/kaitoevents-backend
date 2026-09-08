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
        Schema::create('aesthetic_dimensions', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., "Floral Style"
            $table->string('placeholder')->nullable(); // e.g., "e.g. Garden style / Architectural"
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('aesthetic_dimensions');
    }
};
