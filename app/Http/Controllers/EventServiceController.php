<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignServiceRequest;
use App\Http\Requests\RemoveServiceRequest;
use App\Models\Event;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Builder;

class EventServiceController extends Controller
{
    /**
     * Get all services assigned to an event.
     */
    public function index(Event $event): JsonResponse
    {
        $assignedServices = $event->services()
            ->with(['serviceOptions'])
            ->get()
            ->map(function ($service) {
                // Parse the stored option values from the pivot's 'value' JSON column.
                // Expected shape: { "options": { "1": "Red", "2": 10, ... } }
                $storedValue = $service->pivot->value;
                $configuredOptions = null;
                if ($storedValue) {
                    $decoded = is_string($storedValue) ? json_decode($storedValue, true) : $storedValue;
                    $configuredOptions = $decoded['options'] ?? $decoded;
                }

                return [
                    'id' => $service->id,
                    'name' => $service->name,
                    'description' => $service->description,
                    'category' => null,
                    // Available options for this service (defines what can be configured)
                    'options' => $service->serviceOptions->map(function ($option) {
                        return [
                            'id' => $option->id,
                            'option_name' => $option->option_name,
                            'option_type' => $option->option_type,
                            'option_price' => $option->option_price,
                            'choices' => $option->choices,
                            'is_required' => $option->is_required,
                        ];
                    })->toArray(),
                    // The user's configured option values for this event-service assignment
                    'configured_options' => $configuredOptions,
                    'assignment' => [
                        'quantity' => $service->pivot->quantity ?? 1,
                        'unit_price' => number_format($service->pivot->price ?? $service->price, 2),
                        'unit_price_raw' => $service->pivot->price ?? $service->price,
                        'total_price' => number_format(($service->pivot->price ?? $service->price) * ($service->pivot->quantity ?? 1), 2),
                        'total_price_raw' => ($service->pivot->price ?? $service->price) * ($service->pivot->quantity ?? 1),
                        'original_price' => number_format($service->price, 2),
                        'price_override' => ($service->pivot->price ?? $service->price) != $service->price,
                        'notes' => $service->pivot->notes,
                        'scheduled_date' => null,
                        'scheduled_time' => null,
                        'duration_hours' => null,
                        'status' => 'assigned',
                        'assigned_at' => $service->pivot->created_at?->format('Y-m-d H:i:s'),
                        'assigned_by' => null,
                    ],
                    'service_details' => [
                        'duration_hours' => null,
                        'setup_time_hours' => null,
                        'cleanup_time_hours' => null,
                        'requires_advance_notice' => false,
                        'advance_notice_hours' => null,
                        'max_capacity' => null,
                        'min_capacity' => null,
                        'equipment_required' => [],
                        'staff_required' => [],
                    ],
                ];
            });

        $summary = [
            'total_services' => $assignedServices->count(),
            'total_cost' => $assignedServices->sum('assignment.total_price_raw'),
            'total_cost_formatted' => number_format($assignedServices->sum('assignment.total_price_raw'), 2),
            'services_by_status' => $assignedServices->groupBy('assignment.status')->map->count(),
            'services_by_category' => collect([]), // disabled since category is not present
            'average_service_cost' => $assignedServices->count() > 0 ?
                $assignedServices->sum('assignment.total_price_raw') / $assignedServices->count() : 0,
        ];

        return response()->json([
            'success' => true,
            'data' => $assignedServices->values(),
            'summary' => $summary,
            'event' => [
                'id' => $event->id,
                'status' => $event->status,
                'event_date' => $event->event_date?->format('Y-m-d'),
                'guest_number' => $event->guest_number,
                'budget' => $event->budget ? number_format($event->budget, 2) : null,
                'budget_remaining' => $event->budget ?
                    number_format($event->budget - $assignedServices->sum('assignment.total_price_raw'), 2) : null,
            ],
        ]);
    }

    /**
     * Get available services for an event.
     */
    public function available(Event $event, Request $request): JsonResponse
    {
        $query = Service::with(['serviceOptions'])
            ->whereNotIn('id', $event->services()->pluck('service_id'));

        // Apply basic sorting
        $sortBy = $request->get('sort_by', 'name');
        $sortOrder = $request->get('sort_order', 'asc');

        if (in_array($sortBy, ['name', 'price'])) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $services = $query->get()->map(function ($service) {
            return [
                'id' => $service->id,
                'name' => $service->name,
                'description' => $service->description,
                'price' => number_format($service->price, 2),
                'price_raw' => $service->price,
                'calculated_price' => number_format($service->price, 2),
                'calculated_price_raw' => $service->price,
                'price_adjusted' => false,
                'duration_hours' => null,
                'total_duration_with_setup' => null,
                'is_available' => true,
                'availability_reasons' => [],
                'category' => null,
                'options' => $service->serviceOptions->map(function ($option) {
                    return [
                        'id' => $option->id,
                        'option_name' => $option->option_name,
                        'option_type' => $option->option_type,
                        'option_price' => $option->option_price,
                        'choices' => $option->choices,
                        'is_required' => $option->is_required,
                    ];
                })->toArray(),
                'constraints' => [
                    'max_capacity' => null,
                    'min_capacity' => null,
                    'requires_advance_notice' => false,
                    'advance_notice_hours' => 0,
                    'equipment_required' => [],
                    'staff_required' => [],
                    'location_restrictions' => [],
                    'seasonal_availability' => [],
                ],
                'compatibility' => [
                    'event_type_compatible' => true,
                    'capacity_compatible' => true,
                    'budget_compatible' => true,
                ],
                'recommendations' => [
                    'is_popular' => false,
                    'booking_count' => 0,
                    'average_rating' => null,
                    'complementary_services' => [],
                    'prerequisite_services' => [],
                ],
                'pricing' => [
                    'discount_eligible' => false,
                    'with_tax' => number_format($service->price * 1.08, 2),
                ],
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $services->values(),
            'meta' => [
                'total_available' => $services->count(),
                'filters_applied' => [],
                'event_constraints' => [
                    'guest_number' => $event->guest_number,
                    'budget' => $event->budget,
                    'event_date' => $event->event_date?->format('Y-m-d'),
                    'event_type_id' => $event->event_type_id,
                ],
            ],
        ]);
    }

    /**
     * Assign a service to an event.
     */
    public function assign(AssignServiceRequest $request, Event $event): JsonResponse
    {
        try {
            $validatedData = $request->getValidatedDataWithPricing();
            $service = Service::findOrFail($validatedData['service_id']);

            // Prepare pivot data
            $pivotData = [
                'quantity' => $validatedData['quantity'] ?? 1,
                'price' => $validatedData['unit_price'],
                'notes' => $validatedData['notes'] ?? null,
            ];

            // Persist the service options configuration (value column stores option_id => user_value)
            if (isset($validatedData['value'])) {
                $pivotData['value'] = is_string($validatedData['value'])
                    ? $validatedData['value']
                    : json_encode($validatedData['value']);
            }

            // Assign the service
            $event->services()->attach($validatedData['service_id'], $pivotData);

            // Update event total cost
            $event->updateTotalCost();

            // Load the assigned service with pivot data
            $assignedService = $event->services()
                ->with('category')
                ->where('service_id', $validatedData['service_id'])
                ->first();

            // Get assignment summary
            $assignmentSummary = $request->getAssignmentSummary();

            return response()->json([
                'success' => true,
                'data' => [
                    'service' => $assignmentSummary['service'],
                    'assignment' => $assignmentSummary['assignment'],
                    'event_impact' => [
                        'new_total_cost' => number_format($event->getEstimatedTotal(), 2),
                        'budget_remaining' => $event->budget ?
                            number_format($event->budget - $event->getEstimatedTotal(), 2) : null,
                        'total_services' => $event->services()->count(),
                    ],
                ],
                'message' => "Service '{$service->name}' has been successfully assigned to the event.",
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SERVICE_ASSIGNMENT_FAILED',
                    'message' => 'Failed to assign service to event.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Update a service assignment.
     */
    public function update(Request $request, Event $event, Service $service): JsonResponse
    {
        $request->validate([
            'quantity' => 'sometimes|integer|min:1|max:100',
            'custom_price' => 'sometimes|nullable|numeric|min:0|max:99999.99',
            'notes' => 'sometimes|nullable|string|max:1000',
            'scheduled_date' => 'sometimes|nullable|date|after_or_equal:today',
            'scheduled_time' => 'sometimes|nullable|date_format:H:i',
            'duration_hours' => 'sometimes|nullable|numeric|min:0.5|max:24',
            'status' => 'sometimes|string|in:assigned,confirmed,in_progress,completed,cancelled',
            'value' => 'sometimes|nullable|array',
        ]);

        try {
            // Check if service is assigned to event
            $assignedService = $event->services()->where('service_id', $service->id)->first();

            if (!$assignedService) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'SERVICE_NOT_ASSIGNED',
                        'message' => 'This service is not assigned to the event.',
                    ],
                ], 422);
            }

            // Prepare update data
            $updateData = [];

            if ($request->has('quantity')) {
                $updateData['quantity'] = $request->quantity;
            }

            if ($request->has('custom_price')) {
                $updateData['price'] = $request->custom_price ?? $service->price;
            }

            if ($request->has('notes')) {
                $updateData['notes'] = $request->notes;
            }

            // Persist the service options configuration (value column stores option_id => user_value)
            if ($request->has('value')) {
                $updateData['value'] = is_string($request->value)
                    ? $request->value
                    : json_encode($request->value);
            }

            // Update the pivot record
            $event->services()->updateExistingPivot($service->id, $updateData);

            // Update event total cost
            $event->updateTotalCost();

            // Load updated service
            $updatedService = $event->services()
                ->with('category')
                ->where('service_id', $service->id)
                ->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'service' => [
                        'id' => $service->id,
                        'name' => $service->name,
                        'description' => $service->description,
                    ],
                    'assignment' => [
                        'quantity' => $updatedService->pivot->quantity,
                        'unit_price' => number_format($updatedService->pivot->price, 2),
                        'total_price' => number_format($updatedService->pivot->price * $updatedService->pivot->quantity, 2),
                        'notes' => $updatedService->pivot->notes,
                        'scheduled_date' => null,
                        'scheduled_time' => null,
                        'duration_hours' => null,
                        'status' => 'assigned',
                    ],
                    'event_impact' => [
                        'new_total_cost' => number_format($event->getEstimatedTotal(), 2),
                        'budget_remaining' => $event->budget ?
                            number_format($event->budget - $event->getEstimatedTotal(), 2) : null,
                    ],
                ],
                'message' => "Service assignment has been updated successfully.",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SERVICE_UPDATE_FAILED',
                    'message' => 'Failed to update service assignment.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Remove a service from an event.
     */
    public function remove(RemoveServiceRequest $request, Event $event, Service $service): JsonResponse
    {
        try {
            // Get removal summary before removing
            $removalSummary = $request->getRemovalSummary($event, $service);

            if (empty($removalSummary)) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'SERVICE_NOT_ASSIGNED',
                        'message' => 'This service is not assigned to the event.',
                    ],
                ], 422);
            }

            // Remove the service
            $event->services()->detach($service->id);

            // Update event total cost
            $event->updateTotalCost();

            return response()->json([
                'success' => true,
                'data' => [
                    'removed_service' => $removalSummary['service'],
                    'removal_details' => $removalSummary['removal'],
                    'cost_impact' => [
                        'cost_reduction' => $removalSummary['impact']['cost_reduction'],
                        'new_total_cost' => number_format($event->getEstimatedTotal(), 2),
                        'budget_remaining' => $event->budget ?
                            number_format($event->budget - $event->getEstimatedTotal(), 2) : null,
                    ],
                    'warnings' => $removalSummary['impact']['warnings'],
                    'dependencies_affected' => $removalSummary['impact']['dependencies'],
                ],
                'message' => "Service '{$service->name}' has been successfully removed from the event.",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SERVICE_REMOVAL_FAILED',
                    'message' => 'Failed to remove service from event.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Get service categories for filtering.
     */
    public function categories(): JsonResponse
    {
        $categories = ServiceCategory::active()
            ->withCount(['services' => function ($query) {
                $query->active();
            }])
            ->ordered()
            ->get()
            ->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->category_name,
                    'description' => $category->category_description,
                    'color' => $category->category_color,
                    'icon' => $category->category_icon,
                    'services_count' => $category->services_count,
                    'is_parent' => $category->isParentCategory(),
                    'has_children' => $category->hasChildCategories(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    /**
     * Get service assignment recommendations.
     */
    public function recommendations(Event $event): JsonResponse
    {
        $recommendations = [];

        // Get popular services for this event type
        $popularServices = Service::active()
            ->forEventType($event->event_type_id)
            ->whereNotIn('id', $event->services()->pluck('service_id'))
            ->orderByDesc(function ($query) {
                return $query->selectRaw('COUNT(*)')
                    ->from('event_services')
                    ->whereColumn('event_services.service_id', 'services.id');
            })
            ->limit(5)
            ->get();

        if ($popularServices->count() > 0) {
            $recommendations[] = [
                'type' => 'popular_for_event_type',
                'title' => 'Popular for ' . $event->eventType->event_type_name,
                'description' => 'Services commonly chosen for this type of event',
                'services' => $popularServices->map(function ($service) use ($event) {
                    return $this->formatServiceRecommendation($service, $event);
                }),
            ];
        }

        // Get complementary services based on already assigned services
        $assignedServiceIds = $event->services()->pluck('service_id');
        $complementaryServices = collect();

        foreach ($assignedServiceIds as $serviceId) {
            $service = Service::find($serviceId);
            if ($service && $service->hasComplementaryServices()) {
                $complements = $service->complementaryServices()
                    ->active()
                    ->whereNotIn('id', $assignedServiceIds)
                    ->get();
                $complementaryServices = $complementaryServices->merge($complements);
            }
        }

        if ($complementaryServices->count() > 0) {
            $recommendations[] = [
                'type' => 'complementary',
                'title' => 'Complementary Services',
                'description' => 'Services that work well with your current selections',
                'services' => $complementaryServices->unique('id')->take(5)->map(function ($service) use ($event) {
                    return $this->formatServiceRecommendation($service, $event);
                }),
            ];
        }

        // Get budget-friendly options
        if ($event->budget) {
            $remainingBudget = $event->budget - $event->getEstimatedTotal();

            if ($remainingBudget > 0) {
                $budgetFriendlyServices = Service::active()
                    ->whereNotIn('id', $assignedServiceIds)
                    ->where('price', '<=', $remainingBudget)
                    ->orderBy('price', 'desc')
                    ->limit(5)
                    ->get();

                if ($budgetFriendlyServices->count() > 0) {
                    $recommendations[] = [
                        'type' => 'budget_friendly',
                        'title' => 'Within Budget',
                        'description' => "Services that fit within your remaining budget of $" . number_format($remainingBudget, 2),
                        'services' => $budgetFriendlyServices->map(function ($service) use ($event) {
                            return $this->formatServiceRecommendation($service, $event);
                        }),
                    ];
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => $recommendations,
            'event_context' => [
                'assigned_services_count' => $assignedServiceIds->count(),
                'remaining_budget' => $event->budget ?
                    number_format($event->budget - $event->getEstimatedTotal(), 2) : null,
                'event_type' => $event->eventType->event_type_name,
                'guest_number' => $event->guest_number,
            ],
        ]);
    }

    /**
     * Apply filters to service query.
     */
    private function applyServiceFilters(Builder $query, Request $request, Event $event): void
    {
        // Category filter
        if ($request->filled('category_id')) {
            $query->where('service_category_id', $request->category_id);
        }

        // Price range filter
        if ($request->filled('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }

        if ($request->filled('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        // Duration filter
        if ($request->filled('max_duration')) {
            $query->where('duration_hours', '<=', $request->max_duration);
        }

        // Capacity compatibility
        if ($request->filled('capacity_compatible') && $request->capacity_compatible) {
            $query->availableForCapacity($event->guest_number ?? 0);
        }

        // Event type compatibility
        if ($request->filled('event_type_compatible') && $request->event_type_compatible) {
            $query->forEventType($event->event_type_id);
        }

        // Budget compatibility
        if ($request->filled('budget_compatible') && $request->budget_compatible && $event->budget) {
            $remainingBudget = $event->budget - $event->getEstimatedTotal();
            $query->where('price', '<=', $remainingBudget);
        }

        // Search
        if ($request->filled('search')) {
            $query->search($request->search);
        }

        // Popular services only
        if ($request->filled('popular_only') && $request->popular_only) {
            $query->whereHas('events', function ($q) {
                $q->havingRaw('COUNT(*) > 10');
            });
        }

        // Discount eligible
        if ($request->filled('discount_eligible') && $request->discount_eligible) {
            $query->discountEligible();
        }
    }

    /**
     * Get applied service filters.
     */
    private function getAppliedServiceFilters(Request $request): array
    {
        $filters = [];

        $filterFields = [
            'category_id',
            'min_price',
            'max_price',
            'max_duration',
            'capacity_compatible',
            'event_type_compatible',
            'budget_compatible',
            'search',
            'popular_only',
            'discount_eligible'
        ];

        foreach ($filterFields as $field) {
            if ($request->filled($field)) {
                $filters[$field] = $request->get($field);
            }
        }

        return $filters;
    }

    /**
     * Get reasons why a service is unavailable.
     */
    private function getUnavailabilityReasons(Service $service, Event $event): array
    {
        $reasons = [];

        if (!$service->isActive()) {
            $reasons[] = 'Service is currently inactive';
        }

        if ($service->max_capacity && $event->guest_number > $service->max_capacity) {
            $reasons[] = "Service capacity ({$service->max_capacity}) is less than event guests ({$event->guest_number})";
        }

        if ($service->min_capacity && $event->guest_number < $service->min_capacity) {
            $reasons[] = "Service requires minimum {$service->min_capacity} guests";
        }

        if ($service->eventTypes()->exists() && !$service->eventTypes()->where('event_type_id', $event->event_type_id)->exists()) {
            $reasons[] = 'Service is not compatible with this event type';
        }

        if ($service->seasonal_availability && $event->event_date) {
            $eventMonth = $event->event_date->month;
            if (!in_array($eventMonth, $service->seasonal_availability)) {
                $reasons[] = 'Service is not available during this season';
            }
        }

        return $reasons;
    }

    /**
     * Check event type compatibility.
     */
    private function isEventTypeCompatible(Service $service, Event $event): bool
    {
        if (!$service->eventTypes()->exists()) {
            return true; // No restrictions
        }

        return $service->eventTypes()->where('event_type_id', $event->event_type_id)->exists();
    }

    /**
     * Check capacity compatibility.
     */
    private function isCapacityCompatible(Service $service, Event $event): bool
    {
        if (!$event->guest_number) {
            return true;
        }

        if ($service->max_capacity && $event->guest_number > $service->max_capacity) {
            return false;
        }

        if ($service->min_capacity && $event->guest_number < $service->min_capacity) {
            return false;
        }

        return true;
    }

    /**
     * Check budget compatibility.
     */
    private function isBudgetCompatible(Service $service, Event $event, float $calculatedPrice): bool
    {
        if (!$event->budget) {
            return true;
        }

        $remainingBudget = $event->budget - $event->getEstimatedTotal();
        return $calculatedPrice <= $remainingBudget;
    }

    /**
     * Format service for recommendation.
     */
    private function formatServiceRecommendation(Service $service, Event $event): array
    {
        $calculatedPrice = $service->calculatePriceForEvent($event);

        return [
            'id' => $service->id,
            'name' => $service->service_name,
            'description' => $service->service_description,
            'price' => number_format($calculatedPrice, 2),
            'price_raw' => $calculatedPrice,
            'duration_hours' => $service->duration_hours,
            'category' => $service->category ? [
                'id' => $service->category->id,
                'name' => $service->category->category_name,
                'color' => $service->category->category_color,
            ] : null,
            'booking_count' => $service->getBookingCount(),
            'is_popular' => $service->isPopular(),
            'compatibility_score' => $this->calculateCompatibilityScore($service, $event),
        ];
    }

    /**
     * Calculate compatibility score for recommendations.
     */
    private function calculateCompatibilityScore(Service $service, Event $event): float
    {
        $score = 0;

        // Event type compatibility (30%)
        if ($this->isEventTypeCompatible($service, $event)) {
            $score += 30;
        }

        // Capacity compatibility (25%)
        if ($this->isCapacityCompatible($service, $event)) {
            $score += 25;
        }

        // Budget compatibility (25%)
        $calculatedPrice = $service->calculatePriceForEvent($event);
        if ($this->isBudgetCompatible($service, $event, $calculatedPrice)) {
            $score += 25;
        }

        // Popularity bonus (20%)
        if ($service->isPopular()) {
            $score += 20;
        }

        return min($score, 100); // Cap at 100%
    }
}
