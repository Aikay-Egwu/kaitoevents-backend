<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInventoryRequest;
use App\Http\Requests\UpdateInventoryRequest;
use App\Http\Resources\InventoryResource;
use App\Models\Inventory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    /**
     * List inventory items with extended filtering (search, category tree, location split,
     * condition enum, supplier, date ranges, financials, sort, pagination).
     *
     * OPTIMIZATION FLAGS:
     *   - `?skip_summary=1`   Skip the global summary aggregates (saves 1 merged count/sum query).
     *   - `?include_events=1` OPT-IN eager-load of upcoming events collection + pivot.
     *                         OFF by default for list pages because the events pivot JOIN
     *                         is expensive; use only when `current_events` widget data is
     *                         actually needed (e.g. Inventory Detail page's "Upcoming Events" card).
     *                         Relation counts (events_count, usage_logs_count, repair_logs_count)
     *                         are always included via cheap COUNT subqueries.
     */
    public function index(Request $request): JsonResponse
    {
        // Always eager-load category (needed for category breadcrumbs / filters) and
        // lightweight counts (single COUNT subquery each — no N+1, no JOIN explosion).
        $query = Inventory::with('category')
            ->withCount(['events', 'usageLogs', 'repairLogs']);

        // OPT-IN: Upcoming events collection + pivot. Alias `event_name` AS `name` so
        // InventoryResource's existing $event->name accessor works without changes.
        if ($request->boolean('include_events')) {
            $query->with(['events' => function ($q) {
                $q->where('event_date', '>=', now())
                    ->select(
                        'events.id',
                        'events.event_name AS name',
                        'events.event_date',
                        'events.status',
                        'events.venue_name',
                        'event_inventories.inventory_id',
                        'event_inventories.quantity'
                    );
            }]);
        }

        // Full-text search across 8 text/token columns
        if ($request->filled('search')) {
            $s = '%' . trim($request->search) . '%';
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', $s)
                    ->orWhere('asset_id', 'like', $s)
                    ->orWhere('description', 'like', $s)
                    ->orWhere('color', 'like', $s)
                    ->orWhere('supplier', 'like', $s)
                    ->orWhere('notes', 'like', $s)
                    ->orWhere('location_name', 'like', $s)
                    ->orWhere('location_zone', 'like', $s);
            });
        }

        // Filter by one or more category IDs (comma-separated)
        if ($request->filled('category_id')) {
            $ids = array_map('intval', array_filter(explode(',', (string)$request->category_id)));
            $query->whereIn('inventory_category_id', $ids);
        }

        // Parent category filter — expands to parent + all its children in a single subquery
        if ($request->filled('parent_category_id')) {
            $parentId = (int)$request->parent_category_id;
            $query->whereIn('inventory_category_id', function ($sub) use ($parentId) {
                $sub->select('id')
                    ->from('inventory_categories')
                    ->where(function ($w) use ($parentId) {
                        $w->where('parent_id', $parentId)
                            ->orWhere('id', $parentId);
                    });
            });
        }

        // Filter by normalized location fields (split name/zone or legacy combined)
        if ($request->filled('location_name')) {
            $query->where('location_name', 'like', '%' . $request->location_name . '%');
        }
        if ($request->filled('location_zone')) {
            $query->where('location_zone', 'like', '%' . $request->location_zone . '%');
        }
        if ($request->filled('location') && !$request->filled('location_name')) {
            $loc = '%' . $request->location . '%';
            $query->where(
                fn($q) => $q
                    ->where('location_name', 'like', $loc)
                    ->orWhere('location_zone', 'like', $loc)
            );
        }

        // Condition enum — allows comma-separated list
        if ($request->filled('condition')) {
            $conditionList = array_filter(explode(',', (string)$request->condition));
            $query->whereIn('condition', $conditionList);
        }

        // Supplier fuzzy match
        if ($request->filled('supplier')) {
            $query->where('supplier', 'like', '%' . $request->supplier . '%');
        }

        // Price range filters
        if ($request->filled('min_price') && is_numeric($request->min_price)) {
            $query->where('price', '>=', (float)$request->min_price);
        }
        if ($request->filled('max_price') && is_numeric($request->max_price)) {
            $query->where('price', '<=', (float)$request->max_price);
        }

        // Quantity range filters
        if ($request->filled('min_qty') && is_numeric($request->min_qty)) {
            $query->where('quantity_available', '>=', (int)$request->min_qty);
        }
        if ($request->filled('max_qty') && is_numeric($request->max_qty)) {
            $query->where('quantity_available', '<=', (int)$request->max_qty);
        }

        // Date-based filters
        if ($request->filled('purchase_from')) {
            $query->whereDate('purchase_date', '>=', $request->purchase_from);
        }
        if ($request->filled('purchase_to')) {
            $query->whereDate('purchase_date', '<=', $request->purchase_to);
        }
        if ($request->filled('pat_due_before')) {
            $query->whereDate('pat_service_due', '<=', $request->pat_due_before);
        }

        // Active flag toggle
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Availability preset presets — named shortcuts for common filter combinations
        if ($request->filled('availability_status')) {
            match ($request->availability_status) {
                'retired'       => $query->where('condition', 'retired_written_off'),
                'available'     => $query->where('is_active', true)
                    ->where('condition', '!=', 'retired_written_off')
                    ->where('quantity_available', '>', 0),
                'out_of_stock'  => $query->where('quantity_available', 0),
                'inactive'      => $query->where('is_active', false),
                'needs_repair'  => $query->where('condition', 'needs_repair'),
                default         => null,
            };
        }

        // Only include items that have no confirmed/in_progress events in the future
        if ($request->boolean('available_only')) {
            $query->whereDoesntHave('events', function ($q) {
                $q->where('event_date', '>=', now())
                    ->whereIn('status', ['confirmed', 'in_progress']);
            });
        }

        // Sorting — whitelist of sortable columns (prevents SQL injection via column name)
        $sortBy = $request->get('sort_by', 'name');
        $sortOrder = $request->get('sort_order', 'asc');

        $allowedSorts = [
            'name',
            'asset_id',
            'price',
            'cost_price',
            'current_value',
            'quantity_available',
            'total_quantity',
            'events_used_count',
            'condition',
            'location_name',
            'location_zone',
            'supplier',
            'purchase_date',
            'last_checked',
            'pat_service_due',
            'created_at',
            'updated_at',
        ];
        if (in_array($sortBy, $allowedSorts, true)) {
            $query->orderBy($sortBy, $sortOrder === 'desc' ? 'desc' : 'asc');
        }

        $perPage = min((int)$request->get('per_page', 15), 200);
        $inventory = $query->paginate($perPage);

        // Build pagination meta directly from the paginator array
        $paginatorMeta = $inventory->toArray();
        $pagination = [
            'current_page' => $paginatorMeta['current_page'],
            'total_pages'  => $paginatorMeta['last_page'],
            'total_items'  => $paginatorMeta['total'],
            'per_page'     => $paginatorMeta['per_page'],
            'from'         => $paginatorMeta['from'],
            'to'           => $paginatorMeta['to'],
        ];

        // Optional summary — 7 global counters merged into a single aggregate SELECT so
        // we hit the DB once instead of 7 times. Uses the plain query builder (not the
        // Inventory model) so raw aliases map cleanly to stdClass properties without
        // Eloquent attribute / accessor interference.
        $summary = null;
        if (!$request->boolean('skip_summary')) {
            $row = DB::table('inventories')->selectRaw('
                COUNT(CASE WHEN is_active = 1 THEN 1 END)                                  AS total_active,
                COUNT(CASE WHEN is_active = 0 THEN 1 END)                                  AS total_inactive,
                COUNT(CASE WHEN quantity_available = 0 THEN 1 END)                         AS out_of_stock,
                COUNT(CASE WHEN quantity_available > 0 AND quantity_available <= 5 THEN 1 END) AS low_stock,
                COUNT(CASE WHEN `condition` = ? THEN 1 END)                                AS needs_repair,
                COUNT(CASE WHEN `condition` = ? THEN 1 END)                                AS retired,
                COALESCE(SUM(current_value), 0)                                            AS total_value
            ', ['needs_repair', 'retired_written_off'])->first();

            if ($row) {
                $summary = [
                    'total_active'   => (int) ($row->total_active ?? 0),
                    'total_inactive' => (int) ($row->total_inactive ?? 0),
                    'out_of_stock'   => (int) ($row->out_of_stock ?? 0),
                    'low_stock'      => (int) ($row->low_stock ?? 0),
                    'needs_repair'   => (int) ($row->needs_repair ?? 0),
                    'retired'        => (int) ($row->retired ?? 0),
                    'total_value'    => number_format((float) ($row->total_value ?? 0), 2),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data'    => InventoryResource::collection($inventory),
            'meta'    => array_filter([
                'pagination'      => $pagination,
                'filters_applied' => $this->getAppliedFilters($request),
                'summary'         => $summary,
            ], fn($v) => $v !== null),
        ]);
    }

    public function store(StoreInventoryRequest $request): JsonResponse
    {
        try {
            $inventory = Inventory::create($request->validated());
            $inventory->load(['category']);

            return response()->json([
                'success' => true,
                'data'    => new InventoryResource($inventory),
                'message' => 'Inventory item created successfully.',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'message' => 'Failed to create inventory item.',
                    'code'    => 'CREATION_FAILED',
                    'detail'  => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Single inventory detail — by default includes category, event assignments,
     * usage logs and repair logs (3 tabs on the frontend detail page).
     */
    public function show($id, Request $request): JsonResponse
    {
        try {
            $eager = ['category', 'events', 'usageLogs', 'repairLogs'];
            if ($request->boolean('skip_logs')) {
                $eager = ['category', 'events'];
            }
            $inventory = Inventory::with($eager)
                ->withCount(['events', 'usageLogs', 'repairLogs'])
                ->findOrFail($id);

            if ($request->has('include_stats')) {
                $request->merge(['include_stats' => true]);
            }

            return response()->json([
                'success' => true,
                'data'    => new InventoryResource($inventory),
                'message' => 'Inventory item retrieved successfully.',
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'message' => 'Inventory item not found.',
                    'code'    => 'NOT_FOUND',
                ],
            ], 404);
        }
    }

    public function update(UpdateInventoryRequest $request, $id): JsonResponse
    {
        try {
            DB::beginTransaction();

            $inventory = Inventory::findOrFail($id);

            // Pull only the keys we actually want to persist from validated data.
            // Uses $fillable on the model plus safe-guards so any unknown keys
            // in the payload are silently dropped (defense-in-depth).
            $validated = $request->validated();

            // Keys we accept for updates (must all exist in $fillable on Inventory)
            $allowedKeys = [
                'asset_id',
                'name',
                'inventory_category_id',
                'price',
                'cost_price',
                'description',
                'color',
                'unit',
                'condition',
                'unit_replacement_cost',
                'current_value',
                'purchase_date',
                'last_checked',
                'location_name',
                'location_zone',
                'supplier',
                'pat_service_due',
                'events_used_count',
                'notes',
                'total_revenue_generated',
                'break_even_events',
                'profit_deficit',
                'total_quantity',
                'quantity_available',
                'is_active',
            ];
            $updatePayload = array_intersect_key($validated, array_flip($allowedKeys));

            // 1) Apply fill + capture isDirty BEFORE touching the DB. This
            //    tells us whether there are actual attribute changes compared
            //    to the current DB row.
            $inventory->fill($updatePayload);
            $dirtyAttributes = $inventory->getDirty();

            // 2) Handle uploaded images if any were sent. TODO: wire these
            //    into a real inventory_images relation. For now we acknowledge
            //    them in the debug payload so we don't silently drop file data.
            $imagesUploaded = count($request->file('images') ?? []);

            // 3) Nothing to update (every key either identical or not in payload).
            //    Return a clear "no-op" success instead of pretending we wrote.
            if (empty($dirtyAttributes) && $imagesUploaded === 0) {
                DB::rollBack();
                $inventory->load(['category']);
                return response()->json([
                    'success' => true,
                    'data'    => new InventoryResource($inventory),
                    'message' => 'No changes detected — inventory item was already up to date.',
                    'meta'    => ['no_changes' => true],
                ]);
            }

            // 4) Actually persist
            $saved = $inventory->save();
            if (!$saved) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'error'   => [
                        'message' => 'Failed to persist inventory update.',
                        'code'    => 'SAVE_FAILED',
                        'detail'  => 'Eloquent save() returned false — check DB constraints.',
                    ],
                ], 500);
            }

            // 5) Post-save VERIFICATION: re-read from DB and confirm that the
            //    fields we intended to change actually changed. Catches edge
            //    cases like accessor interference, cast mismatches, race
            //    conditions, or stale DB transactions.
            $fresh = Inventory::with('category')->findOrFail($id);
            $discrepancies = [];
            foreach (array_keys($dirtyAttributes) as $key) {
                $expected = $inventory->getAttribute($key);
                $actual   = $fresh->getAttribute($key);
                // Normalize decimal comparison (string "10.00" vs 10.00) to
                // avoid false positives from PHP float/string casting via $casts.
                $isDecimal = in_array($key, [
                    'price', 'cost_price', 'unit_replacement_cost',
                    'current_value', 'total_revenue_generated', 'profit_deficit',
                ], true);
                if ($isDecimal) {
                    if (round((float)$expected, 2) !== round((float)$actual, 2)) {
                        $discrepancies[$key] = ['expected' => $expected, 'actual' => $actual];
                    }
                } else {
                    if ($expected != $actual) {
                        $discrepancies[$key] = ['expected' => $expected, 'actual' => $actual];
                    }
                }
            }

            if (!empty($discrepancies)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'error'   => [
                        'message' => 'Inventory item could not be verified after update.',
                        'code'    => 'VERIFY_MISMATCH',
                        'detail'  => $discrepancies,
                    ],
                ], 500);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data'    => new InventoryResource($fresh),
                'message' => empty($dirtyAttributes)
                    ? 'Inventory item updated successfully (images only).'
                    : 'Inventory item updated successfully.',
                'meta'    => [
                    'changed_fields' => array_keys($dirtyAttributes),
                    'images_uploaded' => $imagesUploaded,
                ],
            ]);
        } catch (ModelNotFoundException) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'error'   => [
                    'message' => 'Inventory item not found.',
                    'code'    => 'NOT_FOUND',
                ],
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'error'   => [
                    'message' => 'Failed to update inventory item.',
                    'code'    => 'UPDATE_FAILED',
                    'detail'  => $e->getMessage(),
                ],
            ], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $inventory = Inventory::with(['events' => function ($query) {
                $query->where('event_date', '>=', now())
                    ->whereIn('status', ['confirmed', 'in_progress']);
            }])->findOrFail($id);

            if ($inventory->events->count() > 0) {
                $activeEvents = $inventory->events->map(function ($event) {
                    return [
                        'id'                => $event->id,
                        'name'              => $event->name ?? 'Event #' . $event->id,
                        'event_date'        => $event->event_date,
                        'status'            => $event->status,
                        'quantity_assigned' => $event->pivot->quantity ?? 1,
                    ];
                });

                return response()->json([
                    'success' => false,
                    'error'   => [
                        'message' => 'Cannot delete inventory item. It is currently assigned to active events.',
                        'code'    => 'CONSTRAINT_VIOLATION',
                        'details' => ['active_events' => $activeEvents],
                    ],
                ], 422);
            }

            $inventory->delete();

            return response()->json([
                'success' => true,
                'message' => 'Inventory item deleted successfully.',
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'message' => 'Inventory item not found.',
                    'code'    => 'NOT_FOUND',
                ],
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'message' => 'Failed to delete inventory item.',
                    'code'    => 'DELETION_FAILED',
                    'detail'  => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Bulk update of inventory items — extends the existing set of updatable columns.
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $request->validate([
            'items'                         => 'required|array|min:1',
            'items.*.id'                    => 'required|integer|exists:inventories,id',
            'items.*.is_active'             => 'sometimes|boolean',
            'items.*.quantity_available'    => 'sometimes|integer|min:0|max:99999',
            'items.*.total_quantity'        => 'sometimes|integer|min:0|max:99999',
            'items.*.condition'             => 'sometimes|in:excellent_good,fair_wear,needs_repair,retired_written_off',
            'items.*.location_name'         => 'sometimes|string|max:100',
            'items.*.location_zone'         => 'sometimes|nullable|string|max:50',
            'items.*.last_checked'          => 'sometimes|date',
        ]);

        try {
            $updated = [];
            foreach ($request->items as $item) {
                $inv = Inventory::findOrFail($item['id']);
                $payload = collect($item)->except('id')->toArray();
                if (!empty($payload)) {
                    $inv->update($payload);
                    $updated[] = $inv->id;
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Inventory items updated successfully.',
                'data'    => [
                    'updated_count' => count($updated),
                    'updated_items' => $updated,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'message' => 'Failed to update inventory items.',
                    'code'    => 'BULK_UPDATE_FAILED',
                ],
            ], 500);
        }
    }

    /**
     * Basic availability check — how many of this item remain un-assigned on a given date.
     */
    public function checkAvailability(string $id, Request $request): JsonResponse
    {
        try {
            $inventory = Inventory::with(['events'])->findOrFail($id);
            $date = $request->filled('date') ? $request->date : now()->toDateString();

            $assigned = $inventory->events()
                ->whereDate('event_date', $date)
                ->whereIn('status', ['confirmed', 'in_progress'])
                ->sum('event_inventories.quantity');

            $available = max(0, ($inventory->quantity_available ?? 0) - $assigned);

            return response()->json([
                'success' => true,
                'data'    => [
                    'id'                 => $inventory->id,
                    'name'               => $inventory->name,
                    'date'               => $date,
                    'quantity_available' => $inventory->quantity_available,
                    'quantity_assigned'  => (int)$assigned,
                    'quantity_remaining' => $available,
                    'status'             => $available > 0 ? 'available' : 'fully_booked',
                ],
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'error'   => ['message' => 'Inventory item not found.', 'code' => 'NOT_FOUND'],
            ], 404);
        }
    }

    public function availabilityReport(): JsonResponse
    {
        $report = [
            'summary' => [
                'total_items'   => Inventory::count(),
                'active_items'  => Inventory::where('is_active', true)->count(),
                'inactive_items' => Inventory::where('is_active', false)->count(),
                'out_of_stock'  => Inventory::where('quantity_available', 0)->count(),
                'low_stock'     => Inventory::where('quantity_available', '>', 0)
                    ->where('quantity_available', '<=', 5)->count(),
                'needs_repair'  => Inventory::where('condition', 'needs_repair')->count(),
                'retired'       => Inventory::where('condition', 'retired_written_off')->count(),
                'total_value'   => (float)Inventory::sum('current_value'),
            ],
            'by_category' => Inventory::with('category.parent')
                ->selectRaw('inventory_category_id, count(*) as total, sum(quantity_available) as total_quantity, sum(current_value) as total_value')
                ->groupBy('inventory_category_id')
                ->get()
                ->map(function ($row) {
                    return [
                        'category_id'    => $row->inventory_category_id,
                        'category_name'  => $row->category?->category_name ?? 'Unknown',
                        'parent_name'    => $row->category?->parent?->category_name,
                        'total_items'    => $row->total,
                        'total_quantity' => $row->total_quantity,
                        'total_value'    => $row->total_value,
                    ];
                }),
            'by_condition' => Inventory::selectRaw('`condition`, count(*) as c')
                ->groupBy('condition')
                ->get()
                ->pluck('c', 'condition'),
            'low_stock_items' => InventoryResource::collection(
                Inventory::with('category')
                    ->where('is_active', true)
                    ->where('quantity_available', '>', 0)
                    ->where('quantity_available', '<=', 5)
                    ->orderBy('quantity_available')
                    ->get()
            ),
            'pat_due_soon' => InventoryResource::collection(
                Inventory::with('category')
                    ->whereNotNull('pat_service_due')
                    ->whereDate('pat_service_due', '<=', now()->addDays(60))
                    ->orderBy('pat_service_due')
                    ->limit(25)
                    ->get()
            ),
        ];

        return response()->json([
            'success' => true,
            'data'    => $report,
        ]);
    }

    /**
     * Public / anonymous endpoints — minimal fields, active items only, search + category.
     */
    public function publicIndex(Request $request): JsonResponse
    {

        $query = Inventory::with('category')
            ->active()
            ->where('condition', '!=', 'retired_written_off');

        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $query->where(fn($q) => $q
                ->where('name', 'like', $s)
                ->orWhere('description', 'like', $s));
        }
        if ($request->filled('category_id')) {
            $query->where('inventory_category_id', (int)$request->category_id);
        }
        if ($request->filled('parent_category_id')) {
            $parentId = (int)$request->parent_category_id;
            $childIds = DB::table('inventory_categories')
                ->where('parent_id', $parentId)->pluck('id')->all();
            if (count($childIds)) {
                $query->whereIn('inventory_category_id', $childIds);
            }
        }

        $items = $query->orderBy('name')->limit(300)->get();

        return response()->json([
            'success' => true,
            'data'    => InventoryResource::collection($items),
        ]);
    }

    public function publicShow(string $id): JsonResponse
    {
        try {
            $inventory = Inventory::with('category')
                ->active()
                ->where('condition', '!=', 'retired_written_off')
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data'    => new InventoryResource($inventory),
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'error'   => ['message' => 'Inventory item not found.', 'code' => 'NOT_FOUND'],
            ], 404);
        }
    }

    private function getAppliedFilters(Request $request): array
    {
        $filters = [];
        foreach (
            [
                'search',
                'category_id',
                'parent_category_id',
                'location',
                'location_name',
                'location_zone',
                'condition',
                'supplier',
                'min_price',
                'max_price',
                'min_qty',
                'max_qty',
                'purchase_from',
                'purchase_to',
                'pat_due_before',
                'is_active',
                'availability_status',
                'available_only',
                'sort_by',
                'sort_order',
            ] as $key
        ) {
            if ($request->has($key)) {
                $filters[$key] = $request->$key;
            }
        }
        return $filters;
    }
}
