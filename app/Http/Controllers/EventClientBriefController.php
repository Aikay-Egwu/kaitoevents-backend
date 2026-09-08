<?php

namespace App\Http\Controllers;

use App\Http\Requests\EventClientBriefRequest;
use App\Models\AestheticDimension;
use App\Models\Event;
use Illuminate\Http\Request;

/**
 * EventClientBriefController — CRUD for the client's event brief.
 *
 * Manages the events_client_briefs table. Each event has at most one client
 * brief (hasOne relationship), so the controller treats the resource as a
 * singleton.
 *
 * All responses follow the standardized { success, data, message } JSON
 * envelope consumed by the Next.js server-bridge utility.
 */
class EventClientBriefController extends Controller
{
    /**
     * Retrieve the client brief for the given event.
     * Returns 404 if no brief has been created yet.
     */
    public function index(Event $event)
    {
        $clientBrief = $event->clientBriefs;
        
        
        if (!$clientBrief) {
            return response()->json([
                'success' => false,
                'message' => 'No client brief found for this event',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $clientBrief,
        ]);
    }

    /**
     * Create a new client brief for the given event.
     * Validation is delegated to EventClientBriefRequest.
     * The event_id is automatically set by the hasOne relationship.
     */
    public function store(EventClientBriefRequest $request, Event $event)
    {
        $clientBrief = $event->clientBriefs()->create($request->validated());
        //$aestheticSelection = $event->aestheticSelections()->create($request->validated());

        return response()->json([
            'success' => true,
            'data' => $clientBrief,
        ], 201);
    }

    /**
     * Update the existing client brief for the given event.
     * Returns 404 if no brief exists to update.
     */
    public function update(EventClientBriefRequest $request, Event $event)
    {
        $clientBrief = $event->clientBriefs;
        
        if (!$clientBrief) {
            return response()->json([
                'success' => false,
                'message' => 'No client brief found for this event',
            ], 404);
        }

        $clientBrief->update($request->validated());

        return response()->json([
            'success' => true,
            'data' => $clientBrief,
        ]);
    }

    /**
     * Delete the client brief for the given event.
     * Returns 404 if no brief exists to delete.
     */
    public function destroy(Event $event)
    {
        $clientBrief = $event->clientBriefs;
        
        if (!$clientBrief) {
            return response()->json([
                'success' => false,
                'message' => 'No client brief found for this event',
            ], 404);
        }

        $clientBrief->delete();

        return response()->json([
            'success' => true,
            'message' => 'Client brief deleted successfully',
        ]);
    }
}
