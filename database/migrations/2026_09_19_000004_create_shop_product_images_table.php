<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 4. Shop item images — ordered gallery with one primary image per inventory item.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')
                ->constrained('inventories')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('path', 255);
            $table->string('alt_text', 200)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['inventory_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_product_images');
    }
};
