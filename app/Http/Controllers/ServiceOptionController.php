<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\ServiceOption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ServiceOptionController — CRUD for service options.
 *
 * Each service can have many options (hasMany). Each option defines
 * a customizable attribute like "Width", "Supplier", or "Colour"
 * with a type (text, number, boolean, select) and optional price.
 */
class ServiceOptionController extends Controller
{
    /**
     * List all options for a given service.
     */
    public function index(Service $service): JsonResponse
    {
        $options = $service->serviceOptions;

        return response()->json([
            'success' => true,
            'data'    => $options,
        ]);
    }

    /**
     * Create a new option for a service.
     */
    public function store(Request $request, Service $service): JsonResponse
    {
        $validated = $request->validate([
            'option_name'  => 'required|string|max:255',
            'option_type'  => 'required|string|in:text,number,boolean,select',
            'option_price' => 'nullable|numeric|min:0',
            'choices'      => 'nullable|array',
            'choices.*'    => 'string|max:255',
            'is_required'  => 'boolean',
        ]);

        $option = $service->serviceOptions()->create($validated);

        return response()->json([
            'success' => true,
            'data'    => $option,
        ], 201);
    }

    /**
     * Update an existing option for a service.
     */
    public function update(Request $request, Service $service, ServiceOption $option): JsonResponse
    {
        // Ensure the option belongs to this service
        if ($option->service_id !== $service->id) {
            return response()->json([
                'success' => false,
                'message' => 'Option does not belong to this service',
            ], 404);
        }

        $validated = $request->validate([
            'option_name'  => 'sometimes|string|max:255',
            'option_type'  => 'sometimes|string|in:text,number,boolean,select',
            'option_price' => 'nullable|numeric|min:0',
            'choices'      => 'nullable|array',
            'choices.*'    => 'string|max:255',
            'is_required'  => 'boolean',
        ]);

        $option->update($validated);

        return response()->json([
            'success' => true,
            'data'    => $option->fresh(),
        ]);
    }

    /**
     * Delete an option from a service.
     */
    public function destroy(Service $service, ServiceOption $option): JsonResponse
    {
        if ($option->service_id !== $service->id) {
            return response()->json([
                'success' => false,
                'message' => 'Option does not belong to this service',
            ], 404);
        }

        $option->delete();

        return response()->json([
            'success' => true,
            'message' => 'Option deleted successfully',
        ]);
    }
}
