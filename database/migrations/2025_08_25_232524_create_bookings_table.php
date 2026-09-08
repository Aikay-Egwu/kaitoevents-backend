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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_number')->unique();
            $table->string('order_number')->unique();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('prop_cart_id')->nullable()->constrained('prop_carts')->nullOnDelete();
            $table->enum('status', ['pending','confirmed','preparing','out_for_delivery','completed','cancelled','refunded'])->default('pending');
            $table->date('event_start_date')->nullable();
            $table->date('event_end_date')->nullable();
            $table->string('delivery_method')->nullable(); // delivery, pickup
            $table->string('delivery_address_line1')->nullable();
            $table->string('delivery_address_line2')->nullable();
            $table->string('delivery_city')->nullable();
            $table->string('delivery_region')->nullable();
            $table->string('delivery_postcode')->nullable();
            $table->string('delivery_country', 2)->nullable();
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('discount_total', 10, 2)->default(0);
            $table->decimal('tax_total', 10, 2)->default(0);
            $table->decimal('delivery_fee', 10, 2)->default(0);
            $table->decimal('grand_total', 10, 2)->default(0);
            $table->string('currency', 3)->default('GBP');
            $table->decimal('deposit_amount', 10, 2)->nullable();
            $table->timestamp('deposit_paid_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['customer_id', 'status']);
            $table->index(['event_start_date','event_end_date']);
            $table->index(['booking_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
