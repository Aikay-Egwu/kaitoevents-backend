<?php

namespace App\Http\Controllers;

use App\Models\VendorType;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VendorTypeController extends Controller
{
    public function index()
    {
        return response()->json(VendorType::with('vendorCategory')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'vendor_category_id' => 'required|exists:vendor_categories,id',
            'name' => 'required|string|max:255',
        ]);

        $type = VendorType::create([
            'vendor_category_id' => $validated['vendor_category_id'],
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
        ]);

        return response()->json($type, 201);
    }

    public function show(VendorType $vendorType)
    {
        return response()->json($vendorType->load('vendorCategory'));
    }

    public function update(Request $request, VendorType $vendorType)
    {
        $validated = $request->validate([
            'vendor_category_id' => 'sometimes|exists:vendor_categories,id',
            'name' => 'sometimes|string|max:255',
        ]);

        if (isset($validated['name'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $vendorType->update($validated);

        return response()->json($vendorType);
    }

    public function destroy(VendorType $vendorType)
    {
        $vendorType->delete();
        return response()->json(null, 204);
    }
}
