<?php

namespace App\Http\Controllers;

use App\Models\InventoryCategory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Hierarchical Inventory Categories (2 levels max):
 *   - Level 0 = Parents (Dashboard tab — 14 root categories)
 *   - Level 1 = Children ("Category / Type" from each numbered Excel tab)
 */
class InventoryCategoryController extends Controller
{
    /**
     * List categories — supports ?view=tree (nested parents→children, default) or
     * ?view=flat (for select dropdowns) and ?filter=parents / children.
     */
    public function index(Request $request): JsonResponse
    {
        $view = $request->input('view', 'tree');
        $filter = $request->input('filter');

        $query = InventoryCategory::query();

        if ($filter === 'parents') {
            $query->rootCategories();
        } elseif ($filter === 'children') {
            $query->childCategories()->with('parent');
        } else {
            $query->withHierarchy();
        }

        $categories = $query->orderBy('category_name')->get();

        if ($view === 'flat') {
            $flat = $categories->map(function ($category) {
                return [
                    'id'                    => $category->id,
                    'parent_id'             => $category->parent_id,
                    'category_name'         => $category->category_name,
                    'category_description'  => $category->category_description,
                    'category_image'        => $category->category_image,
                    'depth'                 => $category->parent_id === null ? 0 : 1,
                    'inventory_count'       => $category->inventoryItems()->count(),
                    'created_at'            => $category->created_at?->format('Y-m-d H:i:s'),
                    'updated_at'            => $category->updated_at?->format('Y-m-d H:i:s'),
                ];
            })->values();

            return response()->json([
                'success' => true,
                'data'    => $flat,
                'meta'    => ['total' => $flat->count()],
            ]);
        }

        // Tree view — only root rows rendered, each eager-loads direct children
        $roots = $categories->where('parent_id', null)->values()->map(function ($root) use ($categories) {
            return [
                'id'                    => $root->id,
                'parent_id'             => null,
                'category_name'         => $root->category_name,
                'category_description'  => $root->category_description,
                'category_image'        => $root->category_image,
                'inventory_count'       => $root->total_inventory_count,
                'children_count'        => $root->directChildren()->count(),
                'created_at'            => $root->created_at?->format('Y-m-d H:i:s'),
                'updated_at'            => $root->updated_at?->format('Y-m-d H:i:s'),
                'children'              => $categories
                    ->where('parent_id', $root->id)
                    ->values()
                    ->map(function ($child) {
                        return [
                            'id'                    => $child->id,
                            'parent_id'             => $child->parent_id,
                            'category_name'         => $child->category_name,
                            'category_description'  => $child->category_description,
                            'category_image'        => $child->category_image,
                            'inventory_count'       => $child->inventoryItems()->count(),
                            'created_at'            => $child->created_at?->format('Y-m-d H:i:s'),
                            'updated_at'            => $child->updated_at?->format('Y-m-d H:i:s'),
                        ];
                    }),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data'    => $roots,
            'meta'    => [
                'total_parents'  => $roots->count(),
                'total_children' => InventoryCategory::childCategories()->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'parent_id'              => ['nullable', 'integer', 'exists:inventory_categories,id'],
            'category_name'          => [
                'required',
                'string',
                'max:255',
                Rule::unique('inventory_categories', 'category_name'),
            ],
            'category_description'   => ['nullable', 'string', 'max:1000'],
            'category_image'         => ['nullable', 'string', 'max:255'],
        ]);

        $category = InventoryCategory::create($validated);

        return response()->json([
            'success' => true,
            'data'    => $this->serializeCategory($category->loadMissing('parent')),
            'message' => 'Category created successfully',
        ], 201);
    }

    public function show($id): JsonResponse
    {
        try {
            $category = InventoryCategory::with(['parent', 'children'])->findOrFail($id);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'CATEGORY_NOT_FOUND',
                    'message' => 'Inventory category not found',
                ],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->serializeCategory($category),
        ]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $category = InventoryCategory::findOrFail($id);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'CATEGORY_NOT_FOUND',
                    'message' => 'Inventory category not found',
                ],
            ], 404);
        }

        $validated = $request->validate([
            'parent_id'              => [
                'nullable',
                'integer',
                Rule::exists('inventory_categories', 'id')->whereNot('id', $id),
            ],
            'category_name'          => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('inventory_categories', 'category_name')->ignore($category->id),
            ],
            'category_description'   => ['sometimes', 'nullable', 'string', 'max:1000'],
            'category_image'         => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        // Prevent self as parent (infinite cycle)
        if (isset($validated['parent_id']) && (int)$validated['parent_id'] === (int)$id) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'INVALID_PARENT',
                    'message' => 'A category cannot be its own parent.',
                ],
            ], 422);
        }

        $category->update($validated);

        return response()->json([
            'success' => true,
            'data'    => $this->serializeCategory($category->loadMissing('parent')),
            'message' => 'Category updated successfully',
        ]);
    }

    public function destroy($id): JsonResponse
    {
        try {
            $category = InventoryCategory::findOrFail($id);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'CATEGORY_NOT_FOUND',
                    'message' => 'Inventory category not found',
                ],
            ], 404);
        }

        $childCount = $category->directChildren()->count();
        $itemCount  = $category->inventoryItems()->count();

        // Recursive safety — don't delete if either attached items or descendants exist
        if ($itemCount > 0 || $childCount > 0) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'CATEGORY_IN_USE',
                    'message' => 'Cannot delete category because it has inventory items or child categories.',
                    'details' => ['inventory_items' => $itemCount, 'children' => $childCount],
                ],
            ], 422);
        }

        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Category deleted successfully',
        ]);
    }

    private function serializeCategory(InventoryCategory $category): array
    {
        return [
            'id'                    => $category->id,
            'parent_id'             => $category->parent_id,
            'parent_name'           => $category->parent?->category_name,
            'category_name'         => $category->category_name,
            'category_description'  => $category->category_description,
            'category_image'        => $category->category_image,
            'created_at'            => $category->created_at?->format('Y-m-d H:i:s'),
            'updated_at'            => $category->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
