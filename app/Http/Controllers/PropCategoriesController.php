<?php

namespace App\Http\Controllers;

use App\Models\PropCategory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PropCategoriesController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = PropCategory::query();

            // Filter by active status if provided
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Search functionality
            if ($request->has('search')) {
                $search = $request->get('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // Include soft deleted records if requested
            if ($request->boolean('include_deleted')) {
                $query->withTrashed();
            }

            // Load relationships if requested
            if ($request->has('with_props')) {
                $query->with('props');
            }

            // Pagination
            $perPage = $request->get('per_page', 15);
            $categories = $query->orderBy('name')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $categories,
                'message' => 'Property categories retrieved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving property categories',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:prop_categories,name',
                'slug' => 'nullable|string|max:255|unique:prop_categories,slug',
                'description' => 'nullable|string',
                'image' => 'nullable|string|max:255',
                'is_active' => 'boolean'
            ]);

            // Generate slug if not provided
            if (empty($validated['slug'])) {
                $validated['slug'] = Str::slug($validated['name']);
            }

            // Ensure slug is unique
            $originalSlug = $validated['slug'];
            $counter = 1;
            while (PropCategory::where('slug', $validated['slug'])->exists()) {
                $validated['slug'] = $originalSlug . '-' . $counter;
                $counter++;
            }

            // Set default value for is_active if not provided
            if (!isset($validated['is_active'])) {
                $validated['is_active'] = true;
            }

            $category = PropCategory::create($validated);

            return response()->json([
                'success' => true,
                'data' => $category,
                'message' => 'Property category created successfully'
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating property category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, $id): JsonResponse
    {
        try {
            $query = PropCategory::query();

            // Include soft deleted records if requested
            if ($request->boolean('include_deleted')) {
                $query->withTrashed();
            }

            // Load relationships if requested
            if ($request->has('with_props')) {
                $query->with('props');
            }

            $category = $query->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $category,
                'message' => 'Property category retrieved successfully'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Property category not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving property category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $category = PropCategory::findOrFail($id);

            $validated = $request->validate([
                'name' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('prop_categories', 'name')->ignore($category->id)
                ],
                'slug' => [
                    'sometimes',
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('prop_categories', 'slug')->ignore($category->id)
                ],
                'description' => 'sometimes|nullable|string',
                'image' => 'sometimes|nullable|string|max:255',
                'is_active' => 'sometimes|boolean'
            ]);

            // Generate slug if name is updated but slug is not provided
            if (isset($validated['name']) && !isset($validated['slug'])) {
                $validated['slug'] = Str::slug($validated['name']);
                
                // Ensure slug is unique
                $originalSlug = $validated['slug'];
                $counter = 1;
                while (PropCategory::where('slug', $validated['slug'])
                                  ->where('id', '!=', $category->id)
                                  ->exists()) {
                    $validated['slug'] = $originalSlug . '-' . $counter;
                    $counter++;
                }
            }

            $category->update($validated);

            return response()->json([
                'success' => true,
                'data' => $category->fresh(),
                'message' => 'Property category updated successfully'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Property category not found'
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating property category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage (soft delete).
     */
    public function destroy($id): JsonResponse
    {
        try {
            $category = PropCategory::findOrFail($id);

            // Check if category has associated props
            if ($category->props()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete category with associated properties'
                ], 422);
            }

            $category->delete();

            return response()->json([
                'success' => true,
                'message' => 'Property category deleted successfully'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Property category not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting property category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Restore a soft deleted resource.
     */
    public function restore($id): JsonResponse
    {
        try {
            $category = PropCategory::withTrashed()->findOrFail($id);

            if (!$category->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Property category is not deleted'
                ], 422);
            }

            $category->restore();

            return response()->json([
                'success' => true,
                'data' => $category->fresh(),
                'message' => 'Property category restored successfully'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Property category not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error restoring property category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Permanently delete a soft deleted resource.
     */
    public function forceDelete($id): JsonResponse
    {
        try {
            $category = PropCategory::withTrashed()->findOrFail($id);

            if (!$category->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Property category must be soft deleted first'
                ], 422);
            }

            $category->forceDelete();

            return response()->json([
                'success' => true,
                'message' => 'Property category permanently deleted'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Property category not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error permanently deleting property category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle the active status of a category.
     */
    public function toggleStatus($id): JsonResponse
    {
        try {
            $category = PropCategory::findOrFail($id);
            $category->is_active = !$category->is_active;
            $category->save();

            return response()->json([
                'success' => true,
                'data' => $category,
                'message' => 'Property category status updated successfully'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Property category not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating property category status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get only active categories.
     */
    public function active(Request $request): JsonResponse
    {
        try {
            $query = PropCategory::where('is_active', true);

            // Load relationships if requested
            if ($request->has('with_props')) {
                $query->with('props');
            }

            $categories = $query->orderBy('name')->get();

            return response()->json([
                'success' => true,
                'data' => $categories,
                'message' => 'Active property categories retrieved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving active property categories',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}