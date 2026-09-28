<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_client_basket_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_client_basket_id')->constrained('shop_client_baskets')->cascadeOnDelete();
            $table->foreignId('inventory_id')->constrained('inventories')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->json('selected_options')->nullable();
            $table->decimal('unit_price_ex_vat', 12, 2);
            $table->string('variant_label', 255)->nullable();
            $table->string('line_key', 190);
            $table->timestamps();

            $table->unique(['shop_client_basket_id', 'line_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_client_basket_items');
    }
};
