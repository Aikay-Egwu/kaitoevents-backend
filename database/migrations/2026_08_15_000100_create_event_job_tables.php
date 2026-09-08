<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the 3 tables required for the Job / Team management feature.
 *
 * 1. event_job_groups          — Top-level team/group belonging to an event.
 *                                 Each group has a name and a designated team_lead_user_id.
 * 2. event_job_group_members   — Pivot between group and users; each row also
 *                                 has an optional `is_team_lead` flag so the
 *                                 same relationship can both list members and
 *                                 indicate who leads (mirror of team_lead_user_id).
 * 3. event_job_tasks           — Individual jobs owned by a group and event.
 *                                 Tasks may be either on_site or in_house, with
 *                                 an optional duration_minutes estimate.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Groups (teams) attached to events
        Schema::create('event_job_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')
                ->constrained('events')
                ->cascadeOnDelete();
            $table->string('name'); // Required group/team name
            $table->foreignId('team_lead_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete(); // If lead user is deleted, keep the group
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['event_id']);
            $table->index(['team_lead_user_id']);
        });

        // 2. Pivot: group <-> users membership (with is_team_lead flag)
        Schema::create('event_job_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_job_group_id')
                ->constrained('event_job_groups')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->boolean('is_team_lead')->default(false);
            $table->timestamps();

            $table->unique(['event_job_group_id', 'user_id']);
            $table->index(['event_job_group_id']);
            $table->index(['user_id']);
        });

        // 3. Tasks inside each group, also linked directly to event for quick lookups
        Schema::create('event_job_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')
                ->constrained('events')
                ->cascadeOnDelete();
            $table->foreignId('event_job_group_id')
                ->constrained('event_job_groups')
                ->cascadeOnDelete();
            $table->string('title'); // Required task title
            $table->text('instructions')->nullable();
            $table->enum('status', ['pending', 'in_progress', 'completed', 'blocked'])
                ->default('pending');
            $table->enum('location', ['on_site', 'in_house']); // Required classification
            $table->unsignedInteger('duration_minutes')->nullable(); // Optional, configurable estimate
            $table->timestamps();

            $table->index(['event_id']);
            $table->index(['event_job_group_id']);
            $table->index(['status']);
            $table->index(['location']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_job_tasks');
        Schema::dropIfExists('event_job_group_members');
        Schema::dropIfExists('event_job_groups');
    }
};
