<?php

namespace App\Http\Controllers;

use App\Http\Resources\RepairMaintenanceLogResource;
use App\Models\RepairMaintenanceLog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Repair & Maintenance Logs CRUD — PAT tests, repairs, servicing, asset retirement.
 * Auto-populates user_id from the authenticated admin/staff user.
 * When a log is saved with status=completed + new_condition, the parent inventory's
 * condition is auto-updated by the model's booted() saved hook.
 */
class RepairMaintenanceLogController extends Controller
{
    /**
     * Paginated index with filters:
     *   ?inventory_id=, ?status= (pending|in_progress|completed|written_off),
     *   ?new_condition=, ?date_from & _to (date_logged range),
     *   ?technician_user_id=, ?search= (issue, action, notes, repaired_by),
     *   ?min_cost= & ?max_cost=, ?has_cost=bool.
     */
    public function index(Request $request): JsonResponse
    {
        $query = RepairMaintenanceLog::with(['inventory', 'technician', 'user']);

        if ($request->filled('inventory_id')) {
            $ids = array_map('intval', array_filter(explode(',', (string)$request->inventory_id)));
            $query->whereIn('inventory_id', $ids);
        }
        if ($request->filled('status')) {
            $statuses = array_filter(explode(',', (string)$request->status));
            $query->whereIn('status', $statuses);
        }
        if ($request->filled('new_condition')) {
            $query->where('new_condition', $request->new_condition);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('date_logged', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('date_logged', '<=', $request->date_to);
        }
        if ($request->filled('resolved')) {
            if ($request->boolean('resolved')) {
                $query->whereNotNull('date_resolved');
            } else {
                $query->whereNull('date_resolved');
            }
        }
        if ($request->filled('technician_user_id')) {
            $query->where('technician_user_id', (int)$request->technician_user_id);
        }
        if ($request->filled('repaired_by')) {
            $query->where('repaired_by', 'like', '%' . $request->repaired_by . '%');
        }
        if ($request->filled('min_cost') && is_numeric($request->min_cost)) {
            $query->where('cost', '>=', (float)$request->min_cost);
        }
        if ($request->filled('max_cost') && is_numeric($request->max_cost)) {
            $query->where('cost', '<=', (float)$request->max_cost);
        }
        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('issue_work_required', 'like', $s)
                  ->orWhere('action_taken', 'like', $s)
                  ->orWhere('notes', 'like', $s)
                  ->orWhere('repaired_by', 'like', $s);
            });
        }

        $sortBy    = $request->get('sort_by', 'date_logged');
        $sortOrder = $request->get('sort_order', 'desc');
        if (in_array($sortBy, ['date_logged', 'date_resolved', 'status', 'cost', 'created_at', 'updated_at'], true)) {
            $query->orderBy($sortBy, $sortOrder === 'asc' ? 'asc' : 'desc');
        }

        $perPage = min((int)$request->get('per_page', 25), 200);
        $logs    = $query->paginate($perPage);

        $baseSummary = RepairMaintenanceLog::query();
        return response()->json([
            'success' => true,
            'data'    => RepairMaintenanceLogResource::collection($logs),
            'meta'    => [
                'pagination' => [
                    'current_page' => $logs->currentPage(),
                    'total_pages'  => $logs->lastPage(),
                    'total_items'  => $logs->total(),
                    'per_page'     => $logs->perPage(),
                ],
                'summary'    => [
                    'total_logs'   => $logs->total(),
                    'pending'      => (clone $baseSummary)->where('status', 'pending')->count(),
                    'in_progress'  => (clone $baseSummary)->where('status', 'in_progress')->count(),
                    'completed'    => (clone $baseSummary)->where('status', 'completed')->count(),
                    'written_off'  => (clone $baseSummary)->where('status', 'written_off')->count(),
                    'total_cost'   => number_format((float)(clone $baseSummary)->sum('cost'), 2),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'inventory_id'        => ['required', 'integer', 'exists:inventories,id'],
            'issue_work_required' => ['required', 'string'],
            'date_logged'         => ['required', 'date'],
            'date_resolved'       => ['nullable', 'date'],
            'cost'                => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'action_taken'        => ['nullable', 'string'],
            'repaired_by'         => ['nullable', 'string', 'max:150'],
            'technician_user_id'  => ['nullable', 'integer', 'exists:users,id'],
            'new_condition'       => ['nullable', 'in:excellent_good,fair_wear,needs_repair,retired_written_off'],
            'status'              => ['nullable', 'in:pending,in_progress,completed,written_off'],
            'notes'               => ['nullable', 'string'],
        ]);

        try {
            $validated['user_id'] = Auth::id() ?? $validated['user_id'] ?? null;
            $validated['status']  = $validated['status'] ?? 'pending';
            if (!isset($validated['cost'])) {
                $validated['cost'] = 0;
            }

            $log = RepairMaintenanceLog::create($validated);
            $log->loadMissing(['inventory', 'technician', 'user']);

            return response()->json([
                'success' => true,
                'data'    => new RepairMaintenanceLogResource($log),
                'message' => 'Repair/maintenance log created.',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'CREATE_FAILED',
                    'message' => 'Failed to create repair/maintenance log.',
                    'detail'  => $e->getMessage(),
                ],
            ], 500);
        }
    }

    public function show(string $id): JsonResponse
    {
        try {
            $log = RepairMaintenanceLog::with(['inventory', 'technician', 'user'])->findOrFail($id);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'NOT_FOUND', 'message' => 'Repair/maintenance log not found.'],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => new RepairMaintenanceLogResource($log),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $log = RepairMaintenanceLog::findOrFail($id);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'NOT_FOUND', 'message' => 'Repair/maintenance log not found.'],
            ], 404);
        }

        $validated = $request->validate([
            'inventory_id'        => ['sometimes', 'required', 'integer', 'exists:inventories,id'],
            'issue_work_required' => ['sometimes', 'required', 'string'],
            'date_logged'         => ['sometimes', 'required', 'date'],
            'date_resolved'       => ['nullable', 'date'],
            'cost'                => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'action_taken'        => ['nullable', 'string'],
            'repaired_by'         => ['nullable', 'string', 'max:150'],
            'technician_user_id'  => ['nullable', 'integer', 'exists:users,id'],
            'new_condition'       => ['nullable', 'in:excellent_good,fair_wear,needs_repair,retired_written_off'],
            'status'              => ['nullable', 'in:pending,in_progress,completed,written_off'],
            'notes'               => ['nullable', 'string'],
        ]);

        try {
            $log->update($validated);
            $log->loadMissing(['inventory', 'technician', 'user']);

            return response()->json([
                'success' => true,
                'data'    => new RepairMaintenanceLogResource($log),
                'message' => 'Repair/maintenance log updated.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'UPDATE_FAILED',
                    'message' => 'Failed to update repair/maintenance log.',
                    'detail'  => $e->getMessage(),
                ],
            ], 500);
        }
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $log = RepairMaintenanceLog::findOrFail($id);
            $log->delete();
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'NOT_FOUND', 'message' => 'Repair/maintenance log not found.'],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Repair/maintenance log deleted.',
        ]);
    }
}
