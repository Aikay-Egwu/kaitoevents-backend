<?php

/**
 * Repair & Maintenance Logs — captures repairs, PAT tests, servicing, and asset retirement.
 * Mirrors the Excel "🔧 Repair & Maintenance Log" tab with status workflow tracking.
 * inventory_id uses cascadeOnDelete per project conventions (logs die with inventory).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_maintenance_logs', function (Blueprint $table) {
            $table->id();

            // Inventory association
            $table->foreignId('inventory_id')
                ->constrained('inventories')
                ->onDelete('cascade');

            // Issue & resolution timeline
            $table->text('issue_work_required');
            $table->date('date_logged')->index();
            $table->date('date_resolved')->nullable()->index();
            $table->decimal('cost', 10, 2)->default(0);
            $table->text('action_taken')->nullable();

            // Technician — free text (external) OR internal user FK
            $table->string('repaired_by', 150)->nullable();
            $table->foreignId('technician_user_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            // Status workflow
            $table->enum('new_condition', [
                'excellent_good', 'fair_wear', 'needs_repair', 'retired_written_off'
            ])->nullable();
            $table->enum('status', [
                'pending', 'in_progress', 'completed', 'written_off'
            ])->default('pending')->index();

            // Extra notes + audit
            $table->text('notes')->nullable();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_maintenance_logs');
    }
};
