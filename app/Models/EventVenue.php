<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * EventVenue — Venue details associated with an event.
 *
 * Each event has at most one venue record containing logistics,
 * room dimensions, infrastructure, spatial design, table configuration,
 * focal points, and provisions.
 *
 * Table: events_venue
 */
class EventVenue extends Model
{
    use HasFactory;

    protected $table = 'events_venue';

    protected $fillable = [
        'event_id',
        // Basic venue information
        'venue_name',
        'venue_address',
        'venue_contact_person',
        'venue_phone',
        'venue_email',
        'main_room_name',
        'room_length',
        'room_width',
        'ceiling_height_lowest',
        'ceiling_height_apex',
        'floor_surface',
        // Infrastructure
        'lighting_infrastructure',
        'power_points',
        'rigging_points',
        // Venue Logistics
        'has_free_onsite_parking',
        'has_direct_fire_door_access',
        'venue_floor_level',
        'has_stair_flight',
        'stair_flight_details',
        'setup_time_allowed',
        'arrival_protocol',
        'venue_tour_available',
        'venue_tour_appointment',
        // Spatial Design
        'floor_plan_file',
        'spatial_design_notes',
        'table_configuration',
        'total_tables',
        // Focal Points
        'primary_focal_point',
        'secondary_focal_points',
        // Venue Services and Provisions
        'venue_completes_layout',
        'venue_provides_furniture',
        'table_type',
        'rectangular_table_qty',
        'rectangular_table_seating',
        'circle_table_qty',
        'circle_table_seating',
        'chairs_need_covering',
        'venue_provides_table_cloths',
        'venue_provides_table_numbers',
        'venue_provides_napkins',
        'venue_provides_cutleries',
        'venue_provides_glasswares',
        'special_requirements',
        'additional_notes',
    ];

    protected $casts = [
        'room_length' => 'decimal:2',
        'room_width' => 'decimal:2',
        'ceiling_height_lowest' => 'decimal:2',
        'ceiling_height_apex' => 'decimal:2',
        'total_tables' => 'integer',
        'has_free_onsite_parking' => 'boolean',
        'has_direct_fire_door_access' => 'boolean',
        'has_stair_flight' => 'boolean',
        'venue_tour_available' => 'boolean',
        'venue_completes_layout' => 'boolean',
        'venue_provides_furniture' => 'boolean',
        'chairs_need_covering' => 'boolean',
        'venue_provides_table_cloths' => 'boolean',
        'venue_provides_table_numbers' => 'boolean',
        'venue_provides_napkins' => 'boolean',
        'venue_provides_cutleries' => 'boolean',
        'venue_provides_glasswares' => 'boolean',
        'rectangular_table_qty' => 'integer',
        'rectangular_table_seating' => 'integer',
        'circle_table_qty' => 'integer',
        'circle_table_seating' => 'integer',
    ];

    // Relationships
    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    // Accessors
    public function getTotalTableCapacityAttribute()
    {
        $rectangularCapacity = ($this->rectangular_table_qty ?? 0) * ($this->rectangular_table_seating ?? 0);
        $circleCapacity = ($this->circle_table_qty ?? 0) * ($this->circle_table_seating ?? 0);
        
        return $rectangularCapacity + $circleCapacity;
    }

    public function getTotalTablesAttribute()
    {
        return ($this->rectangular_table_qty ?? 0) + ($this->circle_table_qty ?? 0);
    }

    // Business Logic Methods
    public function hasParking(): bool
    {
        return $this->has_free_onsite_parking;
    }

    public function hasAccessibilityFeatures(): bool
    {
        return $this->has_direct_fire_door_access && !$this->has_stair_flight;
    }

    public function providesFurniture(): bool
    {
        return $this->venue_provides_furniture;
    }

    public function providesTableDressing(): bool
    {
        return $this->venue_provides_table_cloths || 
               $this->venue_provides_table_numbers || 
               $this->venue_provides_napkins || 
               $this->venue_provides_cutleries || 
               $this->venue_provides_glasswares;
    }

    public function getVenueLogistics(): array
    {
        return [
            'parking' => $this->has_free_onsite_parking,
            'fire_door_access' => $this->has_direct_fire_door_access,
            'floor_level' => $this->venue_floor_level,
            'stair_flight' => $this->has_stair_flight,
            'stair_details' => $this->stair_flight_details,
            'setup_time' => $this->setup_time_allowed,
            'arrival_protocol' => $this->arrival_protocol,
            'venue_tour' => $this->venue_tour_available,
            'tour_appointment' => $this->venue_tour_appointment,
        ];
    }

    public function getVenueServices(): array
    {
        return [
            'completes_layout' => $this->venue_completes_layout,
            'provides_furniture' => $this->venue_provides_furniture,
            'table_type' => $this->table_type,
            'rectangular_tables' => [
                'quantity' => $this->rectangular_table_qty,
                'seating' => $this->rectangular_table_seating,
            ],
            'circle_tables' => [
                'quantity' => $this->circle_table_qty,
                'seating' => $this->circle_table_seating,
            ],
            'chairs_need_covering' => $this->chairs_need_covering,
        ];
    }

    public function getVenueProvisions(): array
    {
        return [
            'table_cloths' => $this->venue_provides_table_cloths,
            'table_numbers' => $this->venue_provides_table_numbers,
            'napkins' => $this->venue_provides_napkins,
            'cutleries' => $this->venue_provides_cutleries,
            'glasswares' => $this->venue_provides_glasswares,
        ];
    }
}