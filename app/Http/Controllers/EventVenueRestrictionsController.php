<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventVenueRestrictions;
use App\Models\RestrictionType;
use App\Http\Requests\StoreEventVenueRestrictionRequest;
use App\Http\Requests\UpdateEventVenueRestrictionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * EventVenueRestrictionsController — CRUD for venue restrictions on an event.
 *
 * Manages the event_venue_restrictions pivot table. Each row links an event
 * to a restriction type with a venue_position and impact_on_design.
 */
class EventVenueRestrictionsController extends Controller
{
    /**
     * List all restrictions for an event, with the restriction type loaded.
     */
    public function index(Event $event): JsonResponse
    {
        $restrictions = $event->restrictions()
            ->with('restrictionType')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $restrictions,
        ]);
    }

    /**
     * List all available restriction types (for the dropdown / selector).
     */
    public function types(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => RestrictionType::orderBy('name')->get(),
        ]);
    }

    /**
     * Attach a restriction to an event.
     */
    public function store(StoreEventVenueRestrictionRequest $request, Event $event): JsonResponse
    {
        $restriction = $event->restrictions()->attach(
            $request->restriction_type_id,
            [
                'venue_position'   => $request->venue_position,
                'impact_on_design' => $request->impact_on_design,
            ]
        );

        // Fetch the pivot row with its type for the response
        $pivot = EventVenueRestrictions::where('event_id', $event->id)
            ->where('restriction_type_id', $request->restriction_type_id)
            ->with('restrictionType')
            ->first();

        return response()->json([
            'success' => true,
            'data' => $pivot,
        ], 201);
    }

    /**
     * Show a single venue restriction.
     */
    public function show(Event $event, EventVenueRestrictions $restriction): JsonResponse
    {
        if ($restriction->event_id !== $event->id) {
            return response()->json([
                'success' => false,
                'message' => 'Restriction does not belong to this event',
            ], 404);
        }

        $restriction->load('restrictionType');

        return response()->json([
            'success' => true,
            'data' => $restriction,
        ]);
    }

    /**
     * Update a venue restriction's position and impact notes.
     */
    public function update(
        UpdateEventVenueRestrictionRequest $request,
        Event $event,
        EventVenueRestrictions $restriction
    ): JsonResponse {
        if ($restriction->event_id !== $event->id) {
            return response()->json([
                'success' => false,
                'message' => 'Restriction does not belong to this event',
            ], 404);
        }

        $restriction->update($request->validated());
        $restriction->load('restrictionType');

        return response()->json([
            'success' => true,
            'data' => $restriction,
        ]);
    }

    /**
     * Remove a restriction from an event.
     */
    public function destroy(Event $event, EventVenueRestrictions $restriction): JsonResponse
    {
        if ($restriction->event_id !== $event->id) {
            return response()->json([
                'success' => false,
                'message' => 'Restriction does not belong to this event',
            ], 404);
        }

        $restriction->delete();

        return response()->json([
            'success' => true,
            'message' => 'Restriction removed successfully',
        ]);
    }
}
