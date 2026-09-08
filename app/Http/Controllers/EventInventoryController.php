<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Inventory;
use App\Models\InventoryCategory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EventInventoryController — manages inventory assignments for a single event.
 *
 * Endpoints (registered in api.php under admin/events/{event}/inventory):
 *   GET    /                        index()    – list assigned items + summary
 *   GET    /catalog                 catalog()  – searchable business-owned list
 *   GET    /available               available()– alias for catalog()
 *   POST   /                        assign()   – attach new inventory to event
 *   PUT    /{inventory}             update()   – change qty/price (rules below)
 *   DELETE /{inventory}             remove()   – detach from event
 *
 * Pricing rules (mirrored in the frontend dialog, enforced in Event model):
 *   • On initial assign → pivot.price = custom_price ?? inventory.price
 *   • On update with QTY CHANGED + custom_price NOT provided → price RESETS
 *     to inventory.price (restores the base calculation, discarding overrides).
 *   • On update with ONLY custom_price changed (same qty) → override is
 *     persisted as-is and survives subsequent reads / total recalculations.
 *
 * Inventory-ownership guard: only items where `Inventory.is_active=true`
 * (business-owned catalogue items) can be assigned; passing a retired,
 * inactive, or non-existent id returns HTTP 422.
 */
class EventInventoryController extends Controller
{
    /* ------------------------------------------------------------------ */
    /*  Index: assigned items + summary                                   */
    /* ------------------------------------------------------------------ */

    public function index(Event $event): JsonResponse
    {
        $assigned = $event->inventories()
            ->with(['category'])
            ->get()
            ->map(function (Inventory $inventory) {
                $pivot        = $inventory->pivot;
                $qty          = (int) ($pivot->quantity ?? 1);
                $unitPrice    = (float) ($pivot->price ?? $inventory->price);
                $catalogue    = (float) $inventory->price;
                $total        = $unitPrice * $qty;
                $hasOverride  = abs($unitPrice - $catalogue) > 0.0001;

                return [
                    'id'              => $inventory->id,
                    'asset_id'        => $inventory->asset_id,
                    'name'            => $inventory->name,
                    'description'     => $inventory->description,
                    'color'           => $inventory->color,
                    'unit'            => $inventory->unit,
                    'condition'       => $inventory->condition,
                    'location_name'   => $inventory->location_name,
                    'location_zone'   => $inventory->location_zone,
                    'quantity_available' => (int) $inventory->quantity_available,
                    'quantity_total'  => (int) $inventory->total_quantity,
                    'category'        => $inventory->category ? [
                        'id'            => $inventory->category->id,
                        'category_name' => $inventory->category->category_name,
                    ] : null,
                    'assignment'      => [
                        'quantity'         => $qty,
                        'unit_price'       => number_format($unitPrice, 2),
                        'unit_price_raw'   => $unitPrice,
                        'total_price'      => number_format($total, 2),
                        'total_price_raw'  => $total,
                        'original_price'   => number_format($catalogue, 2),
                        'original_price_raw' => $catalogue,
                        'price_override'   => $hasOverride,
                        'assigned_at'      => $pivot->created_at?->format('Y-m-d H:i:s'),
                    ],
                ];
            });

        $totalRaw       = $assigned->sum('assignment.total_price_raw');
        $itemsByCat     = $assigned
            ->groupBy(fn($i) => $i['category']['category_name'] ?? 'Uncategorised')
            ->map(fn($g) => $g->count());
        $overrideCount  = $assigned->filter(fn($i) => $i['assignment']['price_override'])->count();

        $summary = [
            'total_items'           => $assigned->count(),
            'total_quantity'        => $assigned->sum('assignment.quantity'),
            'total_cost'            => $totalRaw,
            'total_cost_formatted'  => number_format($totalRaw, 2),
            'inventory_by_category' => $itemsByCat,
            'price_overrides_count' => $overrideCount,
            'average_item_cost'     => $assigned->count() > 0
                ? round($totalRaw / $assigned->count(), 2)
                : 0,
        ];

        return response()->json([
            'success' => true,
            'data'    => $assigned->values(),
            'summary' => $summary,
            'event'   => [
                'id'              => $event->id,
                'status'          => $event->status,
                'event_date'      => $event->event_date?->format('Y-m-d'),
                'number_of_guests'=> $event->number_of_guests ?? $event->guest_number,
                'budget'          => $event->budget ? number_format($event->budget, 2) : null,
                'budget_remaining'=> $event->budget
                    ? number_format(max(0, (float) $event->budget - $event->getEstimatedTotal()), 2)
                    : null,
                'estimated_total' => number_format($event->getEstimatedTotal(), 2),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Catalog: searchable business-owned inventory                      */
    /* ------------------------------------------------------------------ */

    public function catalog(Event $event, Request $request): JsonResponse
    {
        // Exclude items already assigned to this event.
        // Use pivot column name directly (matches EventController.availableInventory
        // pattern) and apply whereNotIn conditionally because many SQL grammars
        // produce "WHERE id NOT IN ()" (empty set) that returns zero rows.
        $assignedIds = $event->inventories()->pluck('inventory_id')->toArray();

        // Match InventoryController.index() behaviour: is_active filter only
        // when explicitly requested. This means the catalog returns all
        // business-owned inventory (matching the management page which shows
        // 440 rows) — so search returns results from the entire inventory set.
        $query = Inventory::query()->with(['category']);
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if (count($assignedIds) > 0) {
            $query->whereNotIn('id', $assignedIds);
        }

        $this->applyCatalogFilters($query, $request);

        $sortBy    = in_array($request->get('sort_by'), ['name', 'price', 'quantity_available', 'location_name'])
            ? $request->get('sort_by')
            : 'name';
        $sortOrder = $request->get('sort_order') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortBy, $sortOrder);

        $data = $query->get()->map(function (Inventory $inv) use ($event) {
            return [
                'id'                  => $inv->id,
                'asset_id'            => $inv->asset_id,
                'name'                => $inv->name,
                'description'         => $inv->description,
                'color'               => $inv->color,
                'unit'                => $inv->unit,
                'condition'           => $inv->condition,
                'location_name'       => $inv->location_name,
                'location_zone'       => $inv->location_zone,
                'category'            => $inv->category ? [
                    'id'            => $inv->category->id,
                    'category_name' => $inv->category->category_name,
                ] : null,
                'price'               => number_format($inv->price, 2),
                'price_raw'           => (float) $inv->price,
                'cost_price'          => $inv->cost_price,
                'quantity_available'  => (int) $inv->quantity_available,
                'quantity_total'      => (int) $inv->total_quantity,
                // UX flags
                'is_available'        => ($inv->quantity_available ?? 0) > 0,
                'availability_reasons'=> ($inv->quantity_available ?? 0) > 0
                    ? []
                    : ['Item is out of stock for this event.'],
                'event_compatible'    => true,
                'business_owned'      => true,
                'pricing'             => [
                    'subtotal_per_unit' => number_format($inv->price, 2),
                    'with_tax'          => number_format($inv->price * 1.08, 2),
                ],
            ];
        });

        // Build the meta.categories query to reflect the same relaxed is_active
        // semantics as the main query: no active() scope unless caller requested it.
        $categoryFilter = function ($q) use ($request) {
            if ($request->has('is_active')) {
                $q->where('is_active', $request->boolean('is_active'));
            }
        };

        return response()->json([
            'success' => true,
            'data'    => $data->values(),
            'meta'    => [
                'total_available'   => $data->count(),
                'categories'        => InventoryCategory::query()
                    ->whereHas('inventoryItems', $categoryFilter)
                    ->orderBy('category_name')
                    ->get(['id', 'category_name'])
                    ->values(),
                'filters_applied'   => $this->appliedFilters($request),
                'event_constraints' => [
                    'event_date'       => $event->event_date?->format('Y-m-d'),
                    'number_of_guests' => $event->number_of_guests ?? $event->guest_number,
                    'budget'           => $event->budget,
                ],
            ],
        ]);
    }

    /** Alias used by clients that mirror the services route layout. */
    public function available(Event $event, Request $request): JsonResponse
    {
        return $this->catalog($event, $request);
    }

    /* ------------------------------------------------------------------ */
    /*  Assign / Update / Remove                                          */
    /* ------------------------------------------------------------------ */

    public function assign(Request $request, Event $event): JsonResponse
    {
        try {
            $validated = $request->validate([
                'inventory_id' => 'required|integer|exists:inventories,id',
                'quantity'     => 'sometimes|integer|min:1|max:1000',
                'custom_price' => 'sometimes|nullable|numeric|min:0|max:999999.99',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'VALIDATION_FAILED',
                    'message' => $e->getMessage(),
                    'details' => $e->errors(),
                ],
            ], 422);
        }

        $inventory = Inventory::find($validated['inventory_id']);

        // Inventory-ownership / availability guard
        if (!$inventory || !$inventory->is_active) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'NOT_BUSINESS_OWNED',
                    'message' => 'Only active, business-owned inventory items can be assigned to events.',
                ],
            ], 422);
        }
        if ($event->inventories()->where('inventory_id', $inventory->id)->exists()) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'ALREADY_ASSIGNED',
                    'message' => 'This inventory item is already assigned to the event. Use PUT to update quantity/price.',
                ],
            ], 422);
        }

        $qty         = (int) ($validated['quantity'] ?? 1);
        $customPrice = array_key_exists('custom_price', $validated)
            ? (isset($validated['custom_price']) ? (float) $validated['custom_price'] : null)
            : null;

        // Ensure requested quantity does not exceed available stock (edge case
        // guard — concurrent bookings can race; we re-check inside a
        // transaction to reduce risk).
        return DB::transaction(function () use ($event, $inventory, $qty, $customPrice) {
            $freshStock = (int) $inventory->fresh()->quantity_available;
            if ($freshStock < $qty) {
                return response()->json([
                    'success' => false,
                    'error'   => [
                        'code'               => 'QUANTITY_UNAVAILABLE',
                        'message'            => 'Requested quantity exceeds current stock.',
                        'available_quantity' => $freshStock,
                    ],
                ], 422);
            }

            $event->assignInventory($inventory->id, $qty, $customPrice);
            $event->load(['inventories']);

            $unitPrice  = $customPrice ?? (float) $inventory->price;
            $total      = $unitPrice * $qty;

            return response()->json([
                'success' => true,
                'data'    => [
                    'inventory'   => [
                        'id'   => $inventory->id,
                        'name' => $inventory->name,
                    ],
                    'assignment'  => [
                        'quantity'        => $qty,
                        'unit_price'      => number_format($unitPrice, 2),
                        'unit_price_raw'  => $unitPrice,
                        'total_price'     => number_format($total, 2),
                        'total_price_raw' => $total,
                        'price_override'  => $customPrice !== null,
                    ],
                    'event_impact' => [
                        'new_total_cost'    => number_format($event->getEstimatedTotal(), 2),
                        'budget_remaining'  => $event->budget
                            ? number_format(max(0, (float) $event->budget - $event->getEstimatedTotal()), 2)
                            : null,
                        'total_inventories' => $event->inventories()->count(),
                    ],
                ],
                'message' => "Inventory '{$inventory->name}' assigned successfully.",
            ], 201);
        });
    }

    public function update(Request $request, Event $event, Inventory $inventory): JsonResponse
    {
        try {
            $validated = $request->validate([
                'quantity'     => 'sometimes|integer|min:1|max:1000',
                'custom_price' => 'sometimes|nullable|numeric|min:0|max:999999.99',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'VALIDATION_FAILED',
                    'message' => $e->getMessage(),
                    'details' => $e->errors(),
                ],
            ], 422);
        }

        if (!$event->inventories()->where('inventory_id', $inventory->id)->exists()) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'NOT_ASSIGNED',
                    'message' => 'Inventory is not assigned to this event.',
                ],
            ], 422);
        }

        $qty         = array_key_exists('quantity', $validated)     ? (int) $validated['quantity']     : null;
        $customPrice = array_key_exists('custom_price', $validated) ? ($validated['custom_price'] === null ? null : (float) $validated['custom_price']) : null;

        try {
            $state = DB::transaction(function () use ($event, $inventory, $qty, $customPrice) {
                // Stock availability guard when qty is being increased
                if ($qty !== null) {
                    $current = (int) $event->inventories()
                        ->where('inventory_id', $inventory->id)
                        ->value('event_inventories.quantity');
                    if ($qty > $current) {
                        $delta     = $qty - $current;
                        $available = (int) $inventory->fresh()->quantity_available;
                        if ($available < $delta) {
                            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                                response()->json([
                                    'success' => false,
                                    'error'   => [
                                        'code'               => 'QUANTITY_UNAVAILABLE',
                                        'message'            => 'Requested quantity exceeds current stock.',
                                        'available_quantity' => $available,
                                    ],
                                ], 422),
                            );
                        }
                    }
                }

                return $event->updateInventory($inventory->id, $qty, $customPrice);
            });
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            return $e->getResponse();
        } catch (\OutOfBoundsException $e) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'NOT_ASSIGNED', 'message' => $e->getMessage()],
            ], 422);
        }

        $event->load(['inventories']);
        $newTotal = $state['unit_price'] * $state['quantity'];

        return response()->json([
            'success' => true,
            'data'    => [
                'inventory'  => ['id' => $inventory->id, 'name' => $inventory->name],
                'assignment' => [
                    'quantity'        => $state['quantity'],
                    'unit_price'      => number_format($state['unit_price'], 2),
                    'unit_price_raw'  => $state['unit_price'],
                    'total_price'     => number_format($newTotal, 2),
                    'total_price_raw' => $newTotal,
                    'price_override'  => abs($state['unit_price'] - (float) $inventory->price) > 0.0001,
                    'was_reset'       => $state['was_reset'],
                ],
                'event_impact' => [
                    'new_total_cost'   => number_format($event->getEstimatedTotal(), 2),
                    'budget_remaining' => $event->budget
                        ? number_format(max(0, (float) $event->budget - $event->getEstimatedTotal()), 2)
                        : null,
                ],
            ],
            'message' => $state['was_reset']
                ? 'Quantity updated; price reset to catalogue base.'
                : 'Inventory assignment updated.',
        ]);
    }

    public function remove(Event $event, Inventory $inventory): JsonResponse
    {
        if (!$event->inventories()->where('inventory_id', $inventory->id)->exists()) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'NOT_ASSIGNED',
                    'message' => 'Inventory is not assigned to this event.',
                ],
            ], 422);
        }

        $oldQty     = (int) $event->inventories()->where('inventory_id', $inventory->id)->value('event_inventories.quantity');
        $oldUnit    = (float) $event->inventories()->where('inventory_id', $inventory->id)->value('event_inventories.price');
        $costImpact = $oldQty * $oldUnit;

        $event->removeInventory($inventory->id);
        $event->load(['inventories']);

        return response()->json([
            'success' => true,
            'data'    => [
                'removed_inventory' => [
                    'id'   => $inventory->id,
                    'name' => $inventory->name,
                    'qty'  => $oldQty,
                ],
                'cost_impact'       => [
                    'cost_reduction'    => number_format($costImpact, 2),
                    'new_total_cost'    => number_format($event->getEstimatedTotal(), 2),
                    'budget_remaining'  => $event->budget
                        ? number_format(max(0, (float) $event->budget - $event->getEstimatedTotal()), 2)
                        : null,
                ],
            ],
            'message' => "Inventory '{$inventory->name}' removed from event.",
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Internal helpers                                                  */
    /* ------------------------------------------------------------------ */

    private function applyCatalogFilters(Builder $query, Request $request): void
    {
        if ($request->filled('search')) {
            $term = "%{$request->search}%";
            $query->where(function (Builder $q) use ($term) {
                $q->where('name', 'like', $term)
                  ->orWhere('asset_id', 'like', $term)
                  ->orWhere('description', 'like', $term)
                  ->orWhere('color', 'like', $term)
                  ->orWhere('location_name', 'like', $term)
                  ->orWhereHas('category', fn($cq) => $cq->where('category_name', 'like', $term));
            });
        }
        if ($request->filled('category_id')) {
            $query->where('inventory_category_id', $request->category_id);
        }
        if ($request->filled('condition')) {
            $query->where('condition', $request->condition);
        }
        if ($request->filled('min_price')) {
            $query->where('price', '>=', (float) $request->min_price);
        }
        if ($request->filled('max_price')) {
            $query->where('price', '<=', (float) $request->max_price);
        }
        if ($request->filled('location')) {
            $query->where('location_name', 'like', "%{$request->location}%");
        }
        if ($request->boolean('in_stock_only')) {
            $query->where('quantity_available', '>', 0);
        }
    }

    private function appliedFilters(Request $request): array
    {
        $keys = [
            'search', 'category_id', 'condition', 'min_price', 'max_price',
            'location', 'in_stock_only', 'sort_by', 'sort_order',
        ];
        return array_filter(
            $request->only($keys),
            fn($v) => $v !== null && $v !== '',
        );
    }
}
