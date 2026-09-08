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
        Schema::create('events_venue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            
            // Basic venue information
            $table->string('venue_name');
            $table->text('venue_address');
            $table->string('venue_contact_person')->nullable();
            $table->string('venue_phone')->nullable();
            $table->string('venue_email')->nullable();
            $table->string('main_room_name')->nullable();
            $table->decimal('room_length', 8, 2)->nullable();
            $table->decimal('room_width', 8, 2)->nullable();
            $table->decimal('ceiling_height_lowest', 8, 2)->nullable();
            $table->decimal('ceiling_height_apex', 8, 2)->nullable();
            $table->string('floor_surface')->nullable();
            
            // Infrastructure
            $table->text('lighting_infrastructure')->nullable();
            $table->text('power_points')->nullable();
            $table->text('rigging_points')->nullable();
            
            // Venue Logistics (Check list)
            $table->boolean('has_free_onsite_parking')->default(false);
            $table->boolean('has_direct_fire_door_access')->default(false);
            $table->string('venue_floor_level')->nullable(); // specify floor level
            $table->boolean('has_stair_flight')->default(false);
            $table->text('stair_flight_details')->nullable(); // e.g., "from porch"
            $table->text('setup_time_allowed')->nullable(); // Time allowed to access hall for set up
            $table->text('arrival_protocol')->nullable(); // Protocol or persons to contact when arriving
            $table->boolean('venue_tour_available')->default(false);
            $table->text('venue_tour_appointment')->nullable(); // Appointment details for venue tour

            // Spatial Design
            $table->string('floor_plan_file')->nullable(); // Path to uploaded file
            $table->text('spatial_design_notes')->nullable();
            $table->string('table_configuration')->nullable();
            $table->integer('total_tables')->unsigned()->nullable();
            
            // Focal Points
            $table->string('primary_focal_point')->nullable();
            $table->text('secondary_focal_points')->nullable();
            
            // Venue Services and Provisions
            $table->boolean('venue_completes_layout')->default(false); // Venue to complete the layout (positioning of tables and chairs)
            $table->boolean('venue_provides_furniture')->default(false);
            
            // Table Information
            $table->enum('table_type', ['rectangular', 'circle', 'both', 'none'])->nullable();
            $table->integer('rectangular_table_qty')->nullable();
            $table->integer('rectangular_table_seating')->nullable(); // How many seating per rectangular table
            $table->integer('circle_table_qty')->nullable();
            $table->integer('circle_table_seating')->nullable(); // How many seating per circle table
            
            // Chair Information
            $table->boolean('chairs_need_covering')->default(false);
            
            // Dressing/Provisions
            $table->boolean('venue_provides_table_cloths')->default(false);
            $table->boolean('venue_provides_table_numbers')->default(false);
            $table->boolean('venue_provides_napkins')->default(false);
            $table->boolean('venue_provides_cutleries')->default(false);
            $table->boolean('venue_provides_glasswares')->default(false);
            
            // Additional venue details
            $table->text('special_requirements')->nullable();
            $table->text('additional_notes')->nullable();
            
            $table->timestamps();

            // Indexes for better performance
            $table->index(['venue_name']);
            $table->index(['has_free_onsite_parking']);
            $table->index(['venue_provides_furniture']);
            $table->index(['table_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events_venue');
    }
};