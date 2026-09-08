<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Vendor;
use Illuminate\Http\Request;

class EventVendorController extends Controller
{
    /**
     * List vendors assigned to an event.
     */
    public function index(Event $event)
    {
        $vendors = $event->vendors()->with('vendorType.vendorCategory')->get();

        $formattedVendors = $vendors->map(function ($vendor) { 
            return [
                'id' => $vendor->id,
                'name' => $vendor->name,
                'contact_person' => $vendor->contact_person,
                'email' => $vendor->email,
                'phone' => $vendor->phone,
                'website' => $vendor->website,
                'pricing_model' => $vendor->pricing_model,
                'base_price' => $vendor->base_price,
                'vendor_type' => $vendor->vendor_type ? [
                    'id' => $vendor->vendor_type->id,
                    'name' => $vendor->vendor_type->name,
                    'vendor_category' => $vendor->vendor_type->vendor_category ? [
                        'id' => $vendor->vendor_type->vendor_category->id,
                        'name' => $vendor->vendor_type->vendor_category->name,
                    ] : null,
                ] : null,
                'assignment' => [
                    'status' => $vendor->pivot->status ?? 'proposed',
                    'agreed_price' => $vendor->pivot->agreed_price ?? null,
                    'advance_paid' => $vendor->pivot->advance_paid ?? null,
                    'balance_due' => $vendor->pivot->balance_due ?? null,
                    'service_start' => $vendor->pivot->service_start ?? null,
                    'service_end' => $vendor->pivot->service_end ?? null,
                    'contract_path' => $vendor->pivot->contract_path ?? null,
                    'invoice_number' => $vendor->pivot->invoice_number ?? null,
                    'notes' => $vendor->pivot->notes ?? null,
                ],
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formattedVendors,
        ]);
    }

    /**
     * Assign a vendor to an event.
     */
    public function store(Request $request, Event $event)
    {
        $validated = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'status' => 'nullable|in:proposed,shortlisted,confirmed,cancelled,completed',
            'agreed_price' => 'nullable|numeric',
            'service_start' => 'nullable|date',
            'service_end' => 'nullable|date',
        ]);

        if ($event->vendors()->where('vendor_id', $validated['vendor_id'])->exists()) {
            return response()->json(['message' => 'Vendor already assigned'], 422);
        }

        $event->vendors()->attach($validated['vendor_id'], [
            'status' => $validated['status'] ?? 'proposed',
            'agreed_price' => $validated['agreed_price'] ?? null,
            'service_start' => $validated['service_start'] ?? null,
            'service_end' => $validated['service_end'] ?? null,
        ]);

        return response()->json(['message' => 'Vendor assigned successfully'], 201);
    }

    /**
     * Update an assigned vendor's details (pivot data).
     */
    public function update(Request $request, Event $event, Vendor $vendor)
    {
        // Ensure vendor is attached
        if (!$event->vendors()->where('vendor_id', $vendor->id)->exists()) {
            return response()->json(['message' => 'Vendor not assigned to this event'], 404);
        }

        $validated = $request->validate([
            'status' => 'nullable|in:proposed,shortlisted,confirmed,cancelled,completed',
            'agreed_price' => 'nullable|numeric',
            'advance_paid' => 'nullable|numeric',
            'balance_due' => 'nullable|numeric',
            'service_start' => 'nullable|date',
            'service_end' => 'nullable|date',
        ]);

        $event->vendors()->updateExistingPivot($vendor->id, $validated);

        return response()->json(['message' => 'Assignment updated']);
    }

    /**
     * Remove a vendor from an event.
     */
    public function destroy(Event $event, Vendor $vendor)
    {
        $event->vendors()->detach($vendor->id);
        return response()->json(null, 204);
    }
}
