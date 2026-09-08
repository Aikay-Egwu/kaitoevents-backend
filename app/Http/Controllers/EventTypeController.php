<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEventTypeRequest;
use App\Http\Requests\UpdateEventTypeRequest;
use App\Models\EventType;
use Illuminate\Http\JsonResponse;


class EventTypeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => EventType::orderBy('title')->get(),
        ]);
    }

    /**
     * Store a newly created event type.
     */
    public function store(StoreEventTypeRequest $request): JsonResponse
    {
        try {
            $validatedData = $request->validated();

            // Check if event type with same title already exists
            $existingEventType = EventType::where('title', $validatedData['title'])->first();
            if ($existingEventType) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'EVENT_TYPE_EXISTS',
                        'message' => 'An event type with this title already exists.',
                    ],
                ], 422);
            }

            $eventType = EventType::create($validatedData);

            return response()->json([
                'success' => true,
                'data' => $eventType,
                'message' => 'Event type created successfully.',
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'EVENT_TYPE_CREATION_FAILED',
                    'message' => 'Failed to create event type.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Display the specified event type.
     */
    public function show(EventType $eventType): JsonResponse
    {
        try {
            // Load related events count
            $eventType->loadCount('events');

            return response()->json([
                'success' => true,
                'data' => $eventType,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'EVENT_TYPE_FETCH_FAILED',
                    'message' => 'Failed to fetch event type.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Update the specified event type.
     */
    public function update(UpdateEventTypeRequest $request, EventType $eventType): JsonResponse
    {
        try {
            $validatedData = $request->validated();

            // Check if another event type with same title already exists (excluding current one)
            if (isset($validatedData['title'])) {
                $existingEventType = EventType::where('title', $validatedData['title'])
                    ->where('id', '!=', $eventType->id)
                    ->first();

                if ($existingEventType) {
                    return response()->json([
                        'success' => false,
                        'error' => [
                            'code' => 'EVENT_TYPE_EXISTS',
                            'message' => 'An event type with this title already exists.',
                        ],
                    ], 422);
                }
            }

            $eventType->update($validatedData);

            return response()->json([
                'success' => true,
                'data' => $eventType,
                'message' => 'Event type updated successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'EVENT_TYPE_UPDATE_FAILED',
                    'message' => 'Failed to update event type.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Remove the specified event type.
     */
    public function destroy(EventType $eventType): JsonResponse
    {
        try {
            // Check if event type is being used by any events
            $eventsCount = $eventType->events()->count();
            if ($eventsCount > 0) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'EVENT_TYPE_IN_USE',
                        'message' => "Cannot delete event type. It is being used by {$eventsCount} event(s).",
                        'events_count' => $eventsCount,
                    ],
                ], 422);
            }

            $eventType->delete();

            return response()->json([
                'success' => true,
                'message' => 'Event type deleted successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'EVENT_TYPE_DELETION_FAILED',
                    'message' => 'Failed to delete event type.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Get event types statistics.
     */
    public function statistics(): JsonResponse
    {
        try {
            $stats = [
                'total_event_types' => EventType::count(),
                'active_event_types' => EventType::where('status', 'active')->count(),
                'inactive_event_types' => EventType::where('status', 'inactive')->count(),
                'most_used_event_types' => EventType::withCount('events')
                    ->orderBy('events_count', 'desc')
                    ->limit(5)
                    ->get()
                    ->map(function ($eventType) {
                        return [
                            'id' => $eventType->id,
                            'title' => $eventType->title,
                            'events_count' => $eventType->events_count,
                        ];
                    }),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'STATISTICS_FETCH_FAILED',
                    'message' => 'Failed to fetch event types statistics.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Toggle event type status (active/inactive).
     */
    public function toggleStatus(EventType $eventType): JsonResponse
    {
        try {
            $newStatus = $eventType->status === 'active' ? 'inactive' : 'active';
            $eventType->update(['status' => $newStatus]);

            return response()->json([
                'success' => true,
                'data' => $eventType,
                'message' => "Event type status changed to {$newStatus}.",
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'STATUS_TOGGLE_FAILED',
                    'message' => 'Failed to toggle event type status.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }


}