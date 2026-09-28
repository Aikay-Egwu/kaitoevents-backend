<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 7. Shop orders + line items in one migration. Order uses unique KE-YYYYMMDD-XXXX ref;
//    order_items retain snapshot fields so order history survives product deletion.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_ref', 32)->unique();
            $table->string('status', 24)->default('pending'); // pending|awaiting_payment|paid|processing|completed|cancelled
            // Customer & billing
            $table->string('customer_full_name', 120);
            $table->string('customer_email', 160)->index();
            $table->string('customer_phone', 40);
            $table->string('billing_address_line_1', 200);
            $table->string('billing_address_line_2', 200)->nullable();
            $table->string('billing_city', 120);
            $table->string('billing_postcode', 20);
            $table->string('billing_country', 80)->default('United Kingdom');
            // Event context
            $table->date('event_date')->nullable();
            $table->string('event_venue', 255)->nullable();
            // Financials
            $table->decimal('subtotal_ex_vat', 12, 2);
            $table->decimal('vat_amount', 12, 2);
            $table->decimal('shipping_amount', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2);
            // Order metadata
            $table->string('payment_method', 20); // card|bank_transfer|on_arrival
            $table->text('notes')->nullable();
            $table->string('source_ref', 40)->nullable(); // diy / your-design
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('updated_at');
        });

        Schema::create('shop_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_order_id')
                ->constrained('shop_orders')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('inventory_id')
                ->nullable()
                ->constrained('inventories')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            // Snapshot columns — preserved even if product is removed
            $table->string('snapshot_name', 255);
            $table->string('snapshot_slug', 255);
            $table->string('snapshot_variant_label', 255)->nullable();
            $table->decimal('unit_price_ex_vat', 12, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('line_price_ex_vat', 12, 2);
            $table->json('customisations')->nullable(); // { option_key: selected_value }
            $table->timestamps();

            $table->index('shop_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_order_items');
        Schema::dropIfExists('shop_orders');
    }
};
