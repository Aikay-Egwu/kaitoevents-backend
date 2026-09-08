<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventVenue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * EventVenuesController — CRUD for the event venue record.
 *
 * Each event has at most one venue (hasOne). The controller treats the
 * resource as a singleton: index returns the single venue, store creates
 * it, update modifies it, and destroy deletes it.
 */
class EventVenuesController extends Controller
{
    /**
     * Get the venue details for an event.
     */
    public function index(Event $event): JsonResponse
    {
        $venue = $event->venue;

        if (!$venue) {
            return response()->json([
                'success' => false,
                'message' => 'No venue found for this event',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $venue,
        ]);
    }

    /**
     * Create a new venue record for an event.
     */
    public function store(Request $request, Event $event): JsonResponse
    {
        $validated = $request->validate([
            'event_id'        => 'required|exists:events,id',
            'venue_name'      => 'required|string|max:255',
            'venue_address'   => 'required|string',
            'venue_contact_person' => 'nullable|string|max:255',
            'venue_phone'     => 'nullable|string|max:50',
            'venue_email'     => 'nullable|email|max:255',
            'main_room_name'  => 'nullable|string|max:255',
            'room_length'     => 'nullable|numeric',
            'room_width'      => 'nullable|numeric',
            'ceiling_height_lowest' => 'nullable|numeric',
            'ceiling_height_apex'   => 'nullable|numeric',
            'floor_surface'   => 'nullable|string|max:255',
            'lighting_infrastructure' => 'nullable|string',
            'power_points'    => 'nullable|string',
            'rigging_points'  => 'nullable|string',
            'has_free_onsite_parking'   => 'boolean',
            'has_direct_fire_door_access' => 'boolean',
            'venue_floor_level'      => 'nullable|string|max:255',
            'has_stair_flight'       => 'boolean',
            'stair_flight_details'   => 'nullable|string',
            'setup_time_allowed'     => 'nullable|string',
            'arrival_protocol'       => 'nullable|string',
            'venue_tour_available'   => 'boolean',
            'venue_tour_appointment' => 'nullable|string',
            'floor_plan_file'        => 'nullable|string',
            'spatial_design_notes'   => 'nullable|string',
            'table_configuration'    => 'nullable|string|max:255',
            'total_tables'           => 'nullable|integer',
            'primary_focal_point'    => 'nullable|string|max:255',
            'secondary_focal_points' => 'nullable|string',
            'venue_completes_layout'  => 'boolean',
            'venue_provides_furniture' => 'boolean',
            'table_type'       => 'nullable|in:rectangular,circle,both,none',
            'rectangular_table_qty'     => 'nullable|integer',
            'rectangular_table_seating' => 'nullable|integer',
            'circle_table_qty'          => 'nullable|integer',
            'circle_table_seating'      => 'nullable|integer',
            'chairs_need_covering'      => 'boolean',
            'venue_provides_table_cloths'  => 'boolean',
            'venue_provides_table_numbers' => 'boolean',
            'venue_provides_napkins'       => 'boolean',
            'venue_provides_cutleries'     => 'boolean',
            'venue_provides_glasswares'    => 'boolean',
            'special_requirements' => 'nullable|string',
            'additional_notes'     => 'nullable|string',
        ]);

        $venue = $event->venue()->create($validated);

        return response()->json([
            'success' => true,
            'data' => $venue,
        ], 201);
    }

    /**
     * Update the venue record for an event.
     */
    public function update(Request $request, Event $event): JsonResponse
    {
        $venue = $event->venue;

        if (!$venue) {
            return response()->json([
                'success' => false,
                'message' => 'No venue found for this event',
            ], 404);
        }

        $validated = $request->validate([
            'venue_name'      => 'sometimes|string|max:255',
            'venue_address'   => 'sometimes|string',
            'venue_contact_person' => 'nullable|string|max:255',
            'venue_phone'     => 'nullable|string|max:50',
            'venue_email'     => 'nullable|email|max:255',
            'main_room_name'  => 'nullable|string|max:255',
            'room_length'     => 'nullable|numeric',
            'room_width'      => 'nullable|numeric',
            'ceiling_height_lowest' => 'nullable|numeric',
            'ceiling_height_apex'   => 'nullable|numeric',
            'floor_surface'   => 'nullable|string|max:255',
            'lighting_infrastructure' => 'nullable|string',
            'power_points'    => 'nullable|string',
            'rigging_points'  => 'nullable|string',
            'has_free_onsite_parking'   => 'boolean',
            'has_direct_fire_door_access' => 'boolean',
            'venue_floor_level'      => 'nullable|string|max:255',
            'has_stair_flight'       => 'boolean',
            'stair_flight_details'   => 'nullable|string',
            'setup_time_allowed'     => 'nullable|string',
            'arrival_protocol'       => 'nullable|string',
            'venue_tour_available'   => 'boolean',
            'venue_tour_appointment' => 'nullable|string',
            'floor_plan_file'        => 'nullable|string',
            'spatial_design_notes'   => 'nullable|string',
            'table_configuration'    => 'nullable|string|max:255',
            'total_tables'           => 'nullable|integer',
            'primary_focal_point'    => 'nullable|string|max:255',
            'secondary_focal_points' => 'nullable|string',
            'venue_completes_layout'  => 'boolean',
            'venue_provides_furniture' => 'boolean',
            'table_type'       => 'nullable|in:rectangular,circle,both,none',
            'rectangular_table_qty'     => 'nullable|integer',
            'rectangular_table_seating' => 'nullable|integer',
            'circle_table_qty'          => 'nullable|integer',
            'circle_table_seating'      => 'nullable|integer',
            'chairs_need_covering'      => 'boolean',
            'venue_provides_table_cloths'  => 'boolean',
            'venue_provides_table_numbers' => 'boolean',
            'venue_provides_napkins'       => 'boolean',
            'venue_provides_cutleries'     => 'boolean',
            'venue_provides_glasswares'    => 'boolean',
            'special_requirements' => 'nullable|string',
            'additional_notes'     => 'nullable|string',
        ]);

        $venue->update($validated);

        return response()->json([
            'success' => true,
            'data' => $venue,
        ]);
    }

    /**
     * Delete the venue record for an event.
     */
    public function destroy(Event $event): JsonResponse
    {
        $venue = $event->venue;

        if (!$venue) {
            return response()->json([
                'success' => false,
                'message' => 'No venue found for this event',
            ], 404);
        }

        $venue->delete();

        return response()->json([
            'success' => true,
            'message' => 'Venue deleted successfully',
        ]);
    }
}
