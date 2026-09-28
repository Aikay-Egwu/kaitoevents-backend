<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 8. Payment transactions — stores processor REFERENCES only (pi_xxx / simulated UUID).
//    Raw PAN / CVC MUST NEVER be stored. fillable + DB columns contain only references + metadata.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_order_id')
                ->constrained('shop_orders')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('transaction_ref', 64)->unique();
            $table->string('provider', 32); // simulated / stripe
            $table->string('provider_payment_id', 255)->nullable(); // pi_xxx ref only
            $table->string('status', 16)->default('created'); // created|pending|succeeded|failed|refunded
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('GBP');
            $table->json('raw_metadata')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['shop_order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_payment_transactions');
    }
};
