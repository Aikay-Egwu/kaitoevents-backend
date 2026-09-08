<?php

namespace App\Http\Controllers;

use App\Models\VendorCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VendorCategoryController extends Controller
{
    public function index()
    {
        return response()->json(VendorCategory::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $category = VendorCategory::create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json($category, 201);
    }

    public function show(VendorCategory $vendorCategory)
    {
        return response()->json($vendorCategory);
    }

    public function update(Request $request, VendorCategory $vendorCategory)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
        ]);

        if (isset($validated['name'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $vendorCategory->update($validated);

        return response()->json($vendorCategory);
    }

    public function destroy(VendorCategory $vendorCategory)
    {
        $vendorCategory->delete();
        return response()->json(null, 204);
    }
}
