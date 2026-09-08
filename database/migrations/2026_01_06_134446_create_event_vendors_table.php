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
        Schema::create('event_vendors', function (Blueprint $table) {
            $table->id();

            $table->foreignId('event_id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->foreignId('vendor_id')
                  ->constrained()
                  ->cascadeOnDelete();

            // Business data
            $table->enum('status', [
                'proposed',
                'shortlisted',
                'confirmed',
                'cancelled',
                'completed'
            ])->default('proposed');

            $table->decimal('agreed_price', 12, 2)->nullable();
            $table->decimal('advance_paid', 12, 2)->nullable();
            $table->decimal('balance_due', 12, 2)->nullable();

            // Scheduling
            $table->dateTime('service_start')->nullable();
            $table->dateTime('service_end')->nullable();

            // References
            $table->string('contract_path')->nullable();
            $table->string('invoice_number')->nullable();

            // Operational notes
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['event_id', 'vendor_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_vendors');
    }
};
