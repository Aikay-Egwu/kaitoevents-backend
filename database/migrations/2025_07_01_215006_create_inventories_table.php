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
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            // Asset identification
            $table->string('asset_id', 32)->nullable()->unique();
            $table->string('name');
            $table->foreignId('inventory_category_id')->constrained('inventory_categories');
            $table->decimal('price', 10, 2);
            $table->decimal('cost_price', 10, 2);
            // Financial totals (derived from Excel columns Q-S)
            $table->decimal('total_revenue_generated', 12, 2)->default(0);
            $table->unsignedSmallInteger('break_even_events')->nullable();
            $table->decimal('profit_deficit', 12, 2)->nullable();
            $table->text('description')->nullable();
            $table->string('color')->nullable();
            // Classification & metadata
            $table->string('unit', 20)->nullable();
            $table->enum('condition', [
                'excellent_good',
                'fair_wear',
                'needs_repair',
                'retired_written_off',
            ])->default('excellent_good')->index();
            // Valuation decimals
            $table->decimal('unit_replacement_cost', 10, 2)->nullable();
            $table->decimal('current_value', 10, 2)->nullable();
            // Dates & compliance
            $table->date('purchase_date')->nullable();
            $table->date('last_checked')->nullable();

            // Normalized location — split legacy "location" into structured name + zone
            $table->string('location_name', 100)->nullable()->index();
            $table->string('location_zone', 50)->nullable()->index();

            // Supplier & compliance
            $table->string('supplier', 150)->nullable();
            $table->date('pat_service_due')->nullable();

            // Usage counters & notes
            $table->unsignedSmallInteger('events_used_count')->default(0);
            $table->text('notes')->nullable();

            $table->string('location')->nullable();
            $table->unsignedSmallInteger('total_quantity')->nullable();
            $table->unsignedSmallInteger('quantity_available')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};
