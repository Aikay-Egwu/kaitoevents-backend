<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 6. Shop item reviews — 1..5 rating, with approved flag for moderation.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')
                ->constrained('inventories')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('author_name', 80);
            $table->unsignedTinyInteger('rating'); // 1..5
            $table->string('title', 160);
            $table->text('review_text')->nullable();
            $table->date('review_date');
            $table->boolean('is_approved')->default(true)->index();
            $table->timestamps();

            $table->index(['inventory_id', 'review_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_product_reviews');
    }
};
