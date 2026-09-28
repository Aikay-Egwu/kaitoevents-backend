<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 5. Shop item customisation options — Radio/Select controls with JSON list of {value,label,price_delta_ex_vat} choices.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_product_customisation_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')
                ->constrained('inventories')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('option_key', 40);
            $table->string('label', 80);
            $table->string('option_type', 10); // radio / select (SQLite-compatible via enum->VARCHAR)
            $table->boolean('is_required')->default(true);
            $table->integer('sort_order')->default(0);
            $table->json('choices'); // [{ value, label, price_delta_ex_vat }]
            $table->timestamps();

            $table->index(['inventory_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_product_customisation_options');
    }
};
