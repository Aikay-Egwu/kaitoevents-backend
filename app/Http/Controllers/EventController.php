<?php

namespace App\Http\Controllers;

use App\Events\ClientEventCreated;
use App\Events\SendAdminMessage;
use App\Http\Requests\CreateEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Http\Resources\EventResource;
use App\Models\Client;
use App\Models\Event;
use App\Models\EventImage;
use App\Models\EventVenue;
use App\Models\Inventory;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EventController extends Controller
{
    protected $imageUploadService;

    public function __construct(\App\Services\ImageUploadService $imageUploadService)
    {
        $this->imageUploadService = $imageUploadService;
    }

    /**
     * Display a listing of events with admin dashboard functionality.
     */
    public function index(Request $request): JsonResponse
    {
        //exit;
        $query = Event::with(['client', 'eventType', 'venue', 'services', 'inventories', 'invoice']);

        // Apply filters
        $this->applyFilters($query, $request);

        // Apply sorting
        $this->applySorting($query, $request);

        // Pagination
        $perPage = min((int) $request->get('per_page', 30), 100);
        $events = $query->paginate($perPage);

        // Get summary statistics
        $summary = $this->getEventsSummary($request);

        return response()->json([
            'success' => true,
            'data' => EventResource::collection($events),
            'meta' => [
                'pagination' => [
                    'current_page' => $events->currentPage(),
                    'total_pages' => $events->lastPage(),
                    'total_items' => $events->total(),
                    'per_page' => $events->perPage(),
                    'from' => $events->firstItem(),
                    'to' => $events->lastItem(),
                ],
                'summary' => $summary,
                'filters_applied' => $this->getAppliedFilters($request),
            ],
        ]);
    }

    /**
     * Get consultations (events mapped to consultation format).
     */
    public function getConsultations(Request $request): JsonResponse
    {
        $events = Event::with(['client', 'eventType', 'venue'])
            ->orderBy('created_at', 'desc')
            ->get();

        $consultations = $events->map(function ($event) {
            return [
                'id' => $event->id,
                'firstName' => $event->client->firstname ?? '',
                'lastName' => $event->client->lastname ?? '',
                'email' => $event->client->email ?? '',
                'phone' => $event->client->phone ?? '',
                'eventType' => $event->eventType->event_type_name ?? $event->eventType->title ?? '',
                'eventDate' => $event->event_date ? $event->event_date->format('Y-m-d') : '',
                'guestCount' => (string) ($event->guest_number ?? $event->number_of_guests ?? ''),
                'budget' => (string) ($event->budget ?? ''),
                'colorScheme' => $event->color_scheme ?? '',
                'startTime' => $event->start_time ? $event->start_time->format('H:i') : '',
                'specialInstructions' => $event->special_instructions ?? '',
                'hasVenue' => $event->venue ? 'true' : 'false',
                'venueName' => $event->venue_name ?? $event->venue->venue_name ?? '',
                'venueAddress' => $event->venue_address ?? $event->venue->venue_address ?? '',
                'status' => match ($event->status) {
                    'pending' => 'PENDING',
                    'confirmed' => 'APPROVED',
                    'cancelled' => 'REJECTED',
                    default => strtoupper($event->status)
                },
                'createdAt' => $event->created_at ? $event->created_at->toISOString() : '',
                'updatedAt' => $event->updated_at ? $event->updated_at->toISOString() : '',
            ];
        });

        return response()->json($consultations);
    }

    /**
     * Update consultation status.
     */
    public function updateConsultationStatus(Request $request, $id): JsonResponse
    {
        $event = Event::findOrFail($id);
        $status = strtolower($request->status ?? '');

        $newStatus = match ($status) {
            'approved' => 'confirmed',
            'rejected' => 'cancelled',
            default => $status
        };

        if ($newStatus) {
            $event->update(['status' => $newStatus]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Store a newly created event.
     */
    public function store(CreateEventRequest $request): JsonResponse
    {
        //$admin = Auth::user();
        
        //return response()->json($admin);
        try {
            $validatedData = $request->validated();

            $client = null;
            // Handle client creation if needed
            if (!isset($validatedData['client_id']) && isset($validatedData['firstName'])) {
                $client = Client::createOrFirst(['email' => $validatedData['email']], [
                    'firstname' => $validatedData['firstName'],
                    'lastname' => $validatedData['lastName'],
                    'phone' => $validatedData['phone'],
                ]);
                $validatedData['client_id'] = $client->id;
            }

            //extract the venue
            //$venue = [
            //    'venue_name' => $validatedData['venue_name'] ?? '',
            //    'venue_address' => $validatedData['venue_address'] ?? '',
            //];

            // Remove client creation fields from event data
            $eventData = array_merge($request->getEventData(), ["client_id" => $client->id]);
            
            //dd($eventData);
            //populate the event table
            $event = Event::create($eventData);
            $event->load(['client', 'eventType']);

            //popuate the event venue table
            EventVenue::create(array_merge(['event_id' => $event->id], $request->getVenueData()));

            // Handle image uploads
            if ($request->hasFile('images')) {
                $this->imageUploadService->uploadMultiple($request->file('images'), [
                    'event_id' => $event->id,
                    'is_primary' => true // First image will handle primary logic
                ]);
            }

            // Reload event with images
            $event->load(['client', 'eventType', 'venue', 'images']);
            event(new ClientEventCreated($client));


            //notify admin of the new event
            $adminList = User::where('type', 'Admin')->get();;
            foreach ($adminList as $admin) {
                event(new SendAdminMessage([
                    'email' => $admin->email,
                    'name' => $admin->name,
                    'subject' => 'You have a new event created',
                    
                    "message" => "A new event has just been created for {$event->event_date} by {$client->firstname} {$client->lastname} with status {$event->status}. Go over to the admin panel to view details."
                ]));
            }
            return response()->json([
                'success' => true,
                'data' => new EventResource($event),
                'message' => 'Event created successfully.',
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'EVENT_CREATION_FAILED',
                    'message' => 'Failed to create event.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Display the specified event with all relationships.
     */
    public function show(Event $event, Request $request): JsonResponse
    {
        $event->load([
            'client',
            'eventType',
            'venue',
            'services.category',
            'inventories.category',
            'images',
            'invoice.items'
        ]);

        $eventResource = new EventResource($event);

        return response()->json([
            'success' => true,
            'data' => $eventResource,
        ]);
    }

    /**
     * Update the specified event.
     */
    public function update(UpdateEventRequest $request, Event $event): JsonResponse
    {
        try {
            $validatedData = $request->validated();

            $event->update($validatedData);

            // Update or create associated Venue details
            $venueData = $request->only([
                'venue_contact_person',
                'venue_phone',
                'venue_email',
                'main_room_name',
                'room_length',
                'room_width',
                'ceiling_height_lowest',
                'ceiling_height_apex',
                'floor_surface',
                'lighting_infrastructure',
                'power_points',
                'rigging_points',
                'has_free_onsite_parking',
                'has_direct_fire_door_access',
                'venue_floor_level',
                'has_stair_flight',
                'stair_flight_details',
                'setup_time_allowed',
                'arrival_protocol',
                'venue_tour_available',
                'venue_tour_appointment',
                'floor_plan_file',
                'spatial_design_notes',
                'table_configuration',
                'total_tables',
                'primary_focal_point',
                'secondary_focal_points',
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
            ]);

            if (!empty($venueData)) {
                $event->venue()->updateOrCreate(['event_id' => $event->id], $venueData);
            }

            $event->load(['client', 'eventType', 'venue', 'services', 'inventories']);

            return response()->json([
                'success' => true,
                'data' => new EventResource($event),
                'message' => 'Event updated successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'EVENT_UPDATE_FAILED',
                    'message' => 'Failed to update event.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Remove the specified event.
     */
    public function destroy(Event $event): JsonResponse
    {
        try {
            // Check if event can be deleted
            if (in_array($event->status, ['in_progress', 'completed'])) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'CANNOT_DELETE_EVENT',
                        'message' => 'Cannot delete events that are in progress or completed.',
                    ],
                ], 422);
            }

            // Check if event has an invoice
            if ($event->hasInvoice()) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'EVENT_HAS_INVOICE',
                        'message' => 'Cannot delete event with existing invoice.',
                    ],
                ], 422);
            }

            $event->delete();

            return response()->json([
                'success' => true,
                'message' => 'Event deleted successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'EVENT_DELETION_FAILED',
                    'message' => 'Failed to delete event.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Assign a service to an event.
     */
    public function assignService(Request $request, Event $event): JsonResponse
    {
        $request->validate([
            'service_id' => 'required|integer|exists:services,id',
            'quantity' => 'sometimes|integer|min:1|max:100',
            'custom_price' => 'sometimes|nullable|numeric|min:0|max:99999.99',
        ]);

        try {
            $serviceId = $request->service_id;
            $quantity = $request->get('quantity', 1);
            $customPrice = $request->get('custom_price');

            // Check if service is already assigned
            if ($event->services()->where('service_id', $serviceId)->exists()) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'SERVICE_ALREADY_ASSIGNED',
                        'message' => 'Service is already assigned to this event.',
                    ],
                ], 422);
            }

            $event->assignService($serviceId, $quantity, $customPrice);
            $event->load(['services']);

            return response()->json([
                'success' => true,
                'data' => new EventResource($event),
                'message' => 'Service assigned successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SERVICE_ASSIGNMENT_FAILED',
                    'message' => 'Failed to assign service.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Remove a service from an event.
     */
    public function removeService(Event $event, Service $service): JsonResponse
    {
        try {
            // Check if service is assigned to event
            if (!$event->services()->where('service_id', $service->id)->exists()) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'SERVICE_NOT_ASSIGNED',
                        'message' => 'Service is not assigned to this event.',
                    ],
                ], 422);
            }

            $event->removeService($service->id);
            $event->load(['services']);

            return response()->json([
                'success' => true,
                'data' => new EventResource($event),
                'message' => 'Service removed successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SERVICE_REMOVAL_FAILED',
                    'message' => 'Failed to remove service.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Assign inventory to an event.
     */
    public function assignInventory(Request $request, Event $event): JsonResponse
    {
        $request->validate([
            'inventory_id' => 'required|integer|exists:inventories,id',
            'quantity' => 'sometimes|integer|min:1|max:1000',
        ]);

        try {
            $inventoryId = $request->inventory_id;
            $quantity = $request->get('quantity', 1);

            // Check if inventory is available
            $inventory = Inventory::findOrFail($inventoryId);
            if (!$inventory->isAvailable() || $inventory->quantity_available < $quantity) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INVENTORY_NOT_AVAILABLE',
                        'message' => 'Requested quantity is not available.',
                        'available_quantity' => $inventory->quantity_available,
                    ],
                ], 422);
            }

            // Check if inventory is already assigned
            if ($event->inventories()->where('inventory_id', $inventoryId)->exists()) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INVENTORY_ALREADY_ASSIGNED',
                        'message' => 'Inventory is already assigned to this event.',
                    ],
                ], 422);
            }

            $event->assignInventory($inventoryId, $quantity);
            $event->load(['inventories']);

            return response()->json([
                'success' => true,
                'data' => new EventResource($event),
                'message' => 'Inventory assigned successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVENTORY_ASSIGNMENT_FAILED',
                    'message' => 'Failed to assign inventory.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Remove inventory from an event.
     */
    public function removeInventory(Event $event, Inventory $inventory): JsonResponse
    {
        try {
            // Check if inventory is assigned to event
            if (!$event->inventories()->where('inventory_id', $inventory->id)->exists()) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INVENTORY_NOT_ASSIGNED',
                        'message' => 'Inventory is not assigned to this event.',
                    ],
                ], 422);
            }

            $event->removeInventory($inventory->id);
            $event->load(['inventories']);

            return response()->json([
                'success' => true,
                'data' => new EventResource($event),
                'message' => 'Inventory removed successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVENTORY_REMOVAL_FAILED',
                    'message' => 'Failed to remove inventory.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Get available services for an event.
     */
    public function availableServices(Event $event): JsonResponse
    {
        $assignedServiceIds = $event->services()->pluck('service_id')->toArray();

        $availableServices = Service::active()
            ->whereNotIn('id', $assignedServiceIds)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $availableServices->map(function ($service) {
                return [
                    'id' => $service->id,
                    'name' => $service->name,
                    'description' => $service->description,
                    'price' => number_format($service->price, 2),
                    'price_raw' => $service->price,
                    'duration_hours' => $service->duration_hours,
                ];
            }),
        ]);
    }

    /**
     * Get available inventory for an event.
     */
    public function availableInventory(Event $event): JsonResponse
    {
        $assignedInventoryIds = $event->inventories()->pluck('inventory_id')->toArray();

        $availableInventory = Inventory::active()
            ->available()
            ->whereNotIn('id', $assignedInventoryIds)
            ->with('category')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $availableInventory->map(function ($inventory) {
                return [
                    'id' => $inventory->id,
                    'name' => $inventory->name,
                    'description' => $inventory->description,
                    'price' => number_format($inventory->price, 2),
                    'price_raw' => $inventory->price,
                    'quantity_available' => $inventory->quantity_available,
                    'color' => $inventory->color,
                    'location' => $inventory->location,
                    'category' => $inventory->category ? [
                        'id' => $inventory->category->id,
                        'name' => $inventory->category->category_name,
                    ] : null,
                ];
            }),
        ]);
    }

    /**
     * Apply filters to the query.
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        // Status filter
        if ($request->filled('status')) {
            $statuses = is_array($request->status) ? $request->status : [$request->status];
            $query->whereIn('status', $statuses);
        }

        // Date range filter
        if ($request->filled('date_from')) {
            $query->where('event_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('event_date', '<=', $request->date_to);
        }

        // Client filter
        if ($request->filled('client_id')) {
            $query->where('client_id', $request->client_id);
        }

        // Event type filter
        if ($request->filled('event_type_id')) {
            $query->where('event_type_id', $request->event_type_id);
        }

        // Venue filter
        if ($request->filled('venue')) {
            $query->byVenue($request->venue);
        }

        // Guest count filter
        if ($request->filled('min_guests')) {
            $minGuests = (int) $request->min_guests;
            $maxGuests = $request->filled('max_guests') ? (int) $request->max_guests : null;
            $query->byGuestCount($minGuests, $maxGuests);
        }

        // Budget range filter
        if ($request->filled('min_budget')) {
            $query->where('budget', '>=', $request->min_budget);
        }

        if ($request->filled('max_budget')) {
            $query->where('budget', '<=', $request->max_budget);
        }

        // Search filter
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('venue_name', 'like', "%{$search}%")
                    ->orWhere('venue_address', 'like', "%{$search}%")
                    ->orWhere('special_instructions', 'like', "%{$search}%")
                    ->orWhereHas('client', function ($clientQuery) use ($search) {
                        $clientQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        // Time-based filters
        if ($request->filled('time_filter')) {
            switch ($request->time_filter) {
                case 'upcoming':
                    $query->upcoming();
                    break;
                case 'past':
                    $query->past();
                    break;
                case 'today':
                    $query->whereDate('event_date', today());
                    break;
                case 'this_week':
                    $query->whereBetween('event_date', [now()->startOfWeek(), now()->endOfWeek()]);
                    break;
                case 'this_month':
                    $query->whereBetween('event_date', [now()->startOfMonth(), now()->endOfMonth()]);
                    break;
            }
        }
    }

    /**
     * Apply sorting to the query.
     */
    private function applySorting(Builder $query, Request $request): void
    {
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');

        $allowedSortFields = [
            'event_date',
            'created_at',
            'updated_at',
            'status',
            'venue_name',
            'guest_number',
            'budget'
        ];

        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder === 'desc' ? 'desc' : 'asc');
        }

        // Secondary sort by ID for consistency
        $query->orderBy('id', 'desc');
    }

    /**
     * Get events summary statistics.
     */
    private function getEventsSummary(Request $request): array
    {
        $baseQuery = Event::query();
        $this->applyFilters($baseQuery, $request);

        return [
            'total_events' => (clone $baseQuery)->count(),
            'pending_events' => (clone $baseQuery)->where('status', 'pending')->count(),
            'confirmed_events' => (clone $baseQuery)->where('status', 'confirmed')->count(),
            'in_progress_events' => (clone $baseQuery)->where('status', 'in_progress')->count(),
            'completed_events' => (clone $baseQuery)->where('status', 'completed')->count(),
            'cancelled_events' => (clone $baseQuery)->where('status', 'cancelled')->count(),
            'upcoming_events' => (clone $baseQuery)->upcoming()->count(),
            'past_events' => (clone $baseQuery)->past()->count(),
            'events_today' => (clone $baseQuery)->whereDate('event_date', today())->count(),
            'events_this_week' => (clone $baseQuery)->whereBetween('event_date', [now()->startOfWeek(), now()->endOfWeek()])->count(),
            'events_this_month' => (clone $baseQuery)->whereBetween('event_date', [now()->startOfMonth(), now()->endOfMonth()])->count(),
        ];
    }

    /**
     * Get applied filters for response metadata.
     */
    private function getAppliedFilters(Request $request): array
    {
        $filters = [];

        $filterFields = [
            'status',
            'date_from',
            'date_to',
            'client_id',
            'event_type_id',
            'venue',
            'min_guests',
            'max_guests',
            'min_budget',
            'max_budget',
            'search',
            'time_filter'
        ];

        foreach ($filterFields as $field) {
            if ($request->filled($field)) {
                $filters[$field] = $request->get($field);
            }
        }

        return $filters;
    }
}
