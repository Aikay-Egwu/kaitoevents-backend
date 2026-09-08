<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Creates event_tracking_items table for event-specific task/item tracking.
     * Supports categorization (task vs item), status workflow, assignment to staff,
     * and includes performance indexes on event_id and category.
     */
    public function up(): void
    {
        Schema::create('event_tracking_items', function (Blueprint $table) {
            $table->id();

            // Parent event reference — cascade delete removes all items when event is deleted
            $table->foreignId('event_id')
                ->constrained('events')
                ->cascadeOnDelete();

            // Core task/item description
            $table->text('task_description');

            // Category: 'task' for action items, 'item' for physical items to procure
            $table->enum('category', ['task', 'item']);

            // Status workflow: default 'pending'
            $table->enum('status', ['done', 'undone', 'pending'])
                ->default('pending');

            // Optional supplementary details
            $table->text('additional_notes')->nullable();

            // Assigned team member (references users.id — staff/admin users)
            $table->foreignId('assigned_to')
                ->constrained('users')
                ->restrictOnDelete();

            // Performance indexes — category is used for sorting/grouping display
            $table->index('event_id');
            $table->index('category');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_tracking_items');
    }
};
