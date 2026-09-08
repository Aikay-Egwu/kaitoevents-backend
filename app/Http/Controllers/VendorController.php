<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Models\VendorCategory;
use App\Models\VendorType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia; 

class VendorController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        
        // Check if this is an API request (expects JSON)
        if ($request->expectsJson() || $request->is('api/*')) {
            $vendors = Vendor::where('user_id', $user->id)
                ->with('vendorType.vendorCategory')
                ->orderBy('name')
                ->get();

            $vendorTypes = VendorType::with('vendorCategory')->orderBy('name')->get();
            $vendorCategories = VendorCategory::orderBy('name')->get();

            return response()->json([
                'success' => true,
                'data' => $vendors,
                'vendorTypes' => $vendorTypes,
                'categories' => $vendorCategories,
            ]);
        }

        $vendors = Vendor::where('user_id', $user->id)
            ->with('vendorType')
            ->orderBy('name')
            ->get();

        $vendorCategories = VendorCategory::with('vendorTypes')->orderBy('name')->get();
        return response()->json([
            'success' => true,
            'data' => $vendors,
            'vendorCategories' => $vendorCategories,
        ]);

        /* return Inertia::render('vendors/index', [
            'vendors' => $vendors,
            'vendorCategories' => $vendorCategories,
        ]); */
    }

    public function show(Request $request, Vendor $vendor)
    {
        $vendor->load('vendorType.vendorCategory');
        
        return response()->json([
            'success' => true,
            'data' => $vendor,
        ]);
    }

    public function store(Request $request)
    {
        try {
            $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'vendor_type_id' => 'required|exists:vendor_types,id',
            'contact_person' => 'nullable|string|max:255',
            'website' => 'nullable|url|max:255',
            'pricing_model' => 'nullable|string|in:fixed,per-hour,per-head',
            'base_price' => 'nullable|numeric|min:0',
        ]);

        $vendor = Vendor::create(array_merge($validated, ['user_id' => $user->id]));

        // Check if this is an API request
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => true,
                'data' => $vendor->load('vendorType.vendorCategory'),
                'message' => 'Vendor created successfully',
            ], 201);
        }

        return back()->with('success', 'Vendor added successfully.');
        } catch (\Throwable $th) {
            Log::error('Vendor creation failed: ' . $th->getMessage());
        }
    }

    public function update(Request $request, Vendor $vendor)
    {
        $user = $request->user();

        if ($vendor->user_id !== $user->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'vendor_type_id' => 'required|exists:vendor_types,id',
            'contact_person' => 'nullable|string|max:255',
            'website' => 'nullable|url|max:255',
            'pricing_model' => 'nullable|string|in:fixed,per-hour,per-head',
            'base_price' => 'nullable|numeric|min:0',
        ]);

        $vendor->update($validated);

        // Check if this is an API request
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => true,
                'data' => $vendor->fresh('vendorType.vendorCategory'),
                'message' => 'Vendor updated successfully',
            ]);
        }

        return back()->with('success', 'Vendor updated successfully.');
    }

    public function destroy(Request $request, Vendor $vendor)
    {
        $user = $request->user();

        if ($vendor->user_id !== $user->id) {
            abort(403);
        }

        $vendor->delete();

        // Check if this is an API request
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => true,
                'message' => 'Vendor deleted successfully',
            ]);
        }

        return back()->with('success', 'Vendor deleted successfully.');
    }
}
