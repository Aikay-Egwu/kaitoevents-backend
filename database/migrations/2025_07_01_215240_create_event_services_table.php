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
        Schema::create('event_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->onDelete('cascade');
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            
            // Storing the answers as a JSON payload maps perfectly to your diagram's 'value' column
            $table->json('value')->nullable(); 
            
            $table->integer('quantity')->default(1);
            $table->double('price');
            $table->text('notes')->nullable();
            // Usually, an event only has one configuration payload per service
            $table->unique(['event_id', 'service_id']);

            //$table->foreignId('inventory_service_id')->constrained('services')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_services');
    }
};
