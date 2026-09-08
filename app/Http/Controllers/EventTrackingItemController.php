<?php

namespace App\Http\Controllers;

use App\Http\Requests\EventTrackingItemRequest;
use App\Models\Event;
use App\Models\EventTrackingItem;
use Illuminate\Http\JsonResponse;

/**
 * EventTrackingItemController — Full CRUD for event task/item tracking.
 *
 * Manages the event_tracking_items table. Each record is a to-do list entry
 * scoped to a specific event. Items are always returned sorted by category
 * (tasks first, items second) to match the consultation UI grouping.
 *
 * Response envelope follows { success, data, message } for server-bridge compatibility.
 */
class EventTrackingItemController extends Controller
{
    /**
     * List all tracking items for a specific event.
     * Always sorted by category (task first, then item) for display grouping.
     * Eager-loads the assignee (staff user) to avoid N+1 in the UI.
     */
    public function index(Event $event): JsonResponse
    {
        $items = $event->trackingItems()
            ->with('assignee:id,name,email')
            ->orderByRaw("CASE category WHEN 'task' THEN 1 WHEN 'item' THEN 2 END")
            ->orderBy('created_at', 'asc')
            ->get()
            ->each(function ($item) {
                $item->assignee?->makeHidden(['password', 'remember_token']);
            });

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    /**
     * Show a single tracking item.
     */
    public function show(Event $event, EventTrackingItem $trackingItem): JsonResponse
    {
        $trackingItem->load('assignee:id,name,email');
        $trackingItem->assignee?->makeHidden(['password', 'remember_token']);

        return response()->json([
            'success' => true,
            'data' => $trackingItem,
        ]);
    }

    /**
     * Create a new tracking item for the event.
     * The authoritative event_id comes from the route parameter (events/{event})
     * and is force-merged into the data — this prevents mismatch between the
     * URL event and any event_id sent in the request body.
     * Status defaults to 'pending' if not provided.
     */
    public function store(EventTrackingItemRequest $request, Event $event): JsonResponse
    {
        $validated = $request->validated();
        $validated['event_id'] = $event->id; // Authoritative from URL
        $validated['status'] = $validated['status'] ?? 'pending';

        $item = $event->trackingItems()->create($validated);
        $item->load('assignee:id,name,email');
        $item->assignee?->makeHidden(['password', 'remember_token']);

        return response()->json([
            'success' => true,
            'data' => $item,
            'message' => 'Tracking item created successfully',
        ], 201);
    }

    /**
     * Update an existing tracking item (status, assignment, or details).
     * event_id is force-overwritten from route parameter to prevent tampering.
     */
    public function update(
        EventTrackingItemRequest $request,
        Event $event,
        EventTrackingItem $trackingItem
    ): JsonResponse {
        $validated = $request->validated();
        $validated['event_id'] = $event->id; // Authoritative from URL

        $trackingItem->update($validated);
        $trackingItem->load('assignee:id,name,email');
        $trackingItem->assignee?->makeHidden(['password', 'remember_token']);

        return response()->json([
            'success' => true,
            'data' => $trackingItem,
            'message' => 'Tracking item updated successfully',
        ]);
    }

    /**
     * Delete a tracking item.
     */
    public function destroy(Event $event, EventTrackingItem $trackingItem): JsonResponse
    {
        $trackingItem->delete();

        return response()->json([
            'success' => true,
            'message' => 'Tracking item deleted successfully',
        ]);
    }

    /**
     * Quick status toggle endpoint for inline workflow updates.
     * Accepts any valid status value (done / undone / pending).
     */
    public function updateStatus(
        EventTrackingItemRequest $request,
        Event $event,
        EventTrackingItem $trackingItem
    ): JsonResponse {
        $trackingItem->update(['status' => $request->input('status')]);
        $trackingItem->load('assignee:id,name,email');
        $trackingItem->assignee?->makeHidden(['password', 'remember_token']);

        return response()->json([
            'success' => true,
            'data' => $trackingItem,
            'message' => 'Status updated successfully',
        ]);
    }
}
