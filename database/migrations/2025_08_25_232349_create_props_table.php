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
        Schema::create('props', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->foreignId('prop_category_id')->constrained('prop_categories')->onDelete('cascade');
            $table->integer('quantity_available');
            $table->integer('quantity_total');
            $table->string('main_image_url')->nullable();
            $table->decimal('rental_price', 10, 2)->default(0); // per unit
            $table->enum('rental_unit', ['hour','day','event'])->default('day');
            $table->unsignedInteger('stock_quantity')->default(1);
            $table->boolean('in_stock')->default(true); // mirrors your frontend flag
            $table->json('dimensions')->nullable(); // {width, height, depth, unit}
            $table->decimal('weight', 8, 2)->nullable();
            $table->text('setup_instructions')->nullable();
            $table->boolean('requires_setup')->default(false);
            $table->enum('status', ['draft','active','archived'])->default('active');
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['prop_category_id', 'status']);
            $table->index(['name', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('props');
    }
};
