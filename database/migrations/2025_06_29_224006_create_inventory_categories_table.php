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
        Schema::create('inventory_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')
                ->nullable()
                ->index()
                ->constrained('inventory_categories')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('category_name');
            $table->string('category_description')->nullable();
            $table->string('category_image')->nullable();
            $table->unique(['parent_id', 'category_name']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_categories');
    }
};
