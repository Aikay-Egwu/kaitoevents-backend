<?php

namespace App\Http\Controllers;

use App\Http\Resources\EventUsageLogResource;
use App\Models\EventUsageLog;
use App\Models\Inventory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Event Usage Logs CRUD — tracks each deployment / return cycle of an inventory item.
 * Auto-populates user_id from the authenticated admin/staff user.
 * Increments inventory.events_used_count when a log is created.
 */
class EventUsageLogController extends Controller
{
    /**
     * Paginated index with filters: ?inventory_id=, ?event_id=, ?event_ref=,
     *   ?event_date_from= & _to=, ?date_returned (null/not_null/date),
     *   ?condition_back=, ?search= (event name/client/ref/notes), ?user_id=.
     */
    public function index(Request $request): JsonResponse
    {
        $query = EventUsageLog::with(['inventory', 'event', 'user']);

        if ($request->filled('inventory_id')) {
            $ids = array_map('intval', array_filter(explode(',', (string)$request->inventory_id)));
            $query->whereIn('inventory_id', $ids);
        }
        if ($request->filled('event_id')) {
            $query->where('event_id', (int)$request->event_id);
        }
        if ($request->filled('event_ref')) {
            $query->where('event_ref', 'like', '%' . $request->event_ref . '%');
        }
        if ($request->filled('event_name')) {
            $query->where('event_name_client', 'like', '%' . $request->event_name . '%');
        }
        if ($request->filled('event_date_from')) {
            $query->whereDate('event_date', '>=', $request->event_date_from);
        }
        if ($request->filled('event_date_to')) {
            $query->whereDate('event_date', '<=', $request->event_date_to);
        }
        if ($request->filled('return_status')) {
            if ($request->return_status === 'returned') {
                $query->whereNotNull('date_returned');
            } elseif ($request->return_status === 'outstanding') {
                $query->whereNull('date_returned');
            }
        }
        if ($request->filled('condition_back')) {
            $query->where('condition_back', $request->condition_back);
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', (int)$request->user_id);
        }
        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('event_ref', 'like', $s)
                  ->orWhere('event_name_client', 'like', $s)
                  ->orWhere('notes_damage_action', 'like', $s);
            });
        }

        $sortBy    = $request->get('sort_by', 'event_date');
        $sortOrder = $request->get('sort_order', 'desc');
        if (in_array($sortBy, ['event_date', 'date_returned', 'created_at', 'quantity_deployed', 'updated_at'], true)) {
            $query->orderBy($sortBy, $sortOrder === 'asc' ? 'asc' : 'desc');
        }

        $perPage = min((int)$request->get('per_page', 25), 200);
        $logs    = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => EventUsageLogResource::collection($logs),
            'meta'    => [
                'pagination'      => [
                    'current_page' => $logs->currentPage(),
                    'total_pages'  => $logs->lastPage(),
                    'total_items'  => $logs->total(),
                    'per_page'     => $logs->perPage(),
                ],
                'summary' => [
                    'total_logs'      => $logs->total(),
                    'outstanding'     => (clone $query)->whereNull('date_returned')->count(),
                    'total_deployed'  => (clone $query)->sum('quantity_deployed'),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'inventory_id'        => ['required', 'integer', 'exists:inventories,id'],
            'event_id'            => ['nullable', 'integer', 'exists:events,id'],
            'event_ref'           => ['nullable', 'string', 'max:50'],
            'event_name_client'   => ['nullable', 'string', 'max:255'],
            'event_date'          => ['required', 'date'],
            'quantity_deployed'   => ['required', 'integer', 'min:1', 'max:99999'],
            'condition_out'       => ['nullable', 'in:excellent_good,fair_wear,needs_repair,retired_written_off'],
            'condition_back'      => ['nullable', 'in:excellent_good,fair_wear,needs_repair,retired_written_off'],
            'date_returned'       => ['nullable', 'date'],
            'notes_damage_action' => ['nullable', 'string'],
        ]);

        try {
            $validated['user_id'] = Auth::id() ?? $validated['user_id'] ?? null;
            $log = EventUsageLog::create($validated);

            // Increment events_used_count counter on the parent inventory
            Inventory::whereKey($log->inventory_id)->increment('events_used_count');

            $log->loadMissing(['inventory', 'event', 'user']);

            return response()->json([
                'success' => true,
                'data'    => new EventUsageLogResource($log),
                'message' => 'Event usage log created.',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'CREATE_FAILED', 'message' => 'Failed to create usage log.', 'detail' => $e->getMessage()],
            ], 500);
        }
    }

    public function show(string $id): JsonResponse
    {
        try {
            $log = EventUsageLog::with(['inventory', 'event', 'user'])->findOrFail($id);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'NOT_FOUND', 'message' => 'Event usage log not found.'],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => new EventUsageLogResource($log),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $log = EventUsageLog::findOrFail($id);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'NOT_FOUND', 'message' => 'Event usage log not found.'],
            ], 404);
        }

        $validated = $request->validate([
            'inventory_id'        => ['sometimes', 'required', 'integer', 'exists:inventories,id'],
            'event_id'            => ['nullable', 'integer', 'exists:events,id'],
            'event_ref'           => ['nullable', 'string', 'max:50'],
            'event_name_client'   => ['nullable', 'string', 'max:255'],
            'event_date'          => ['sometimes', 'required', 'date'],
            'quantity_deployed'   => ['sometimes', 'required', 'integer', 'min:1', 'max:99999'],
            'condition_out'       => ['nullable', 'in:excellent_good,fair_wear,needs_repair,retired_written_off'],
            'condition_back'      => ['nullable', 'in:excellent_good,fair_wear,needs_repair,retired_written_off'],
            'date_returned'       => ['nullable', 'date'],
            'notes_damage_action' => ['nullable', 'string'],
        ]);

        try {
            $log->update($validated);
            $log->loadMissing(['inventory', 'event', 'user']);

            return response()->json([
                'success' => true,
                'data'    => new EventUsageLogResource($log),
                'message' => 'Event usage log updated.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'UPDATE_FAILED', 'message' => 'Failed to update usage log.', 'detail' => $e->getMessage()],
            ], 500);
        }
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $log = EventUsageLog::findOrFail($id);
            $log->delete();
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'NOT_FOUND', 'message' => 'Event usage log not found.'],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Event usage log deleted.',
        ]);
    }
}
