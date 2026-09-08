<?php

/**
 * Event Usage Logs — records each inventory asset deployment to and return from an event.
 * Mirrors the Excel "🔄 Event Usage Log" tab columns plus structured FK relationships.
 * inventory_id uses cascadeOnDelete per project conventions (logs die with inventory).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_usage_logs', function (Blueprint $table) {
            $table->id();

            // Inventory association
            $table->foreignId('inventory_id')
                ->constrained('inventories')
                ->onDelete('cascade');

            // Event association — structured FK or free-text reference
            $table->foreignId('event_id')
                ->nullable()
                ->constrained('events')
                ->onDelete('set null');
            $table->string('event_ref', 50)->nullable();
            $table->string('event_name_client', 255)->nullable();
            $table->date('event_date')->nullable()->index();

            // Deployment / return data
            $table->unsignedSmallInteger('quantity_deployed')->default(1);
            $table->enum('condition_out', [
                'excellent_good',
                'fair_wear',
                'needs_repair',
                'retired_written_off'
            ])->nullable();
            $table->enum('condition_back', [
                'excellent_good',
                'fair_wear',
                'needs_repair',
                'retired_written_off'
            ])->nullable();
            $table->date('date_returned')->nullable()->index();
            $table->text('notes_damage_action')->nullable();

            // Audit
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_usage_logs');
    }
};
