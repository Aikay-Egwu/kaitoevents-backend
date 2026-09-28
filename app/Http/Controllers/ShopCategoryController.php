<?php

namespace App\Http\Controllers;

// ShopCategoryController — admin management of the inventory categories published to the shop.
// Categories live in `inventory_categories`; the shop exposes those flagged `for_shop`.
// DELETE rejects categories that still hold inventory unless ?force=1.

use App\Http\Requests\Shop\StoreShopCategoryRequest;
use App\Http\Requests\Shop\UpdateShopCategoryRequest;
use App\Http\Resources\Shop\ShopCategoryResource;
use App\Models\InventoryCategory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ShopCategoryController extends Controller
{
    /** Maps validated request keys onto inventory_categories columns. */
    private const FIELD_MAP = [
        'name'           => 'category_name',
        'slug'           => 'slug',
        'description'    => 'category_description',
        'thumbnail_path' => 'category_image',
        'parent_id'      => 'parent_id',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min((int) $request->input('per_page', 50), 200);
        $builder = InventoryCategory::withCount('shopItems')->forShop()->ordered();

        if ($request->filled('is_active')) {
            $builder->where('for_shop', (bool) $request->input('is_active'));
        }

        return ShopCategoryResource::collection($builder->paginate($perPage))
            ->additional(['success' => true]);
    }

    public function store(StoreShopCategoryRequest $request): JsonResponse
    {
        $valid = $request->validated();

        $payload = [];
        foreach (self::FIELD_MAP as $input => $column) {
            if (array_key_exists($input, $valid)) {
                $payload[$column] = $valid[$input];
            }
        }
        $payload['for_shop'] = (bool) ($valid['for_shop'] ?? $valid['is_active'] ?? true);

        $category = InventoryCategory::create($payload);

        return (new ShopCategoryResource($category->loadCount('shopItems')))
            ->additional(['success' => true])
            ->response()
            ->setStatusCode(201);
    }

    public function show(InventoryCategory $category): JsonResponse
    {
        $category->load('shopItems.images')->loadCount('shopItems');

        return (new ShopCategoryResource($category))
            ->additional(['success' => true])
            ->response();
    }

    public function update(UpdateShopCategoryRequest $request, InventoryCategory $category): JsonResponse
    {
        $valid = $request->validated();

        $payload = [];
        foreach (self::FIELD_MAP as $input => $column) {
            if (array_key_exists($input, $valid)) {
                $payload[$column] = $valid[$input];
            }
        }
        if (array_key_exists('for_shop', $valid)) {
            $payload['for_shop'] = (bool) $valid['for_shop'];
        } elseif (array_key_exists('is_active', $valid)) {
            $payload['for_shop'] = (bool) $valid['is_active'];
        }

        $category->update($payload);

        return (new ShopCategoryResource($category->loadCount('shopItems')))
            ->additional(['success' => true])
            ->response();
    }

    /**
     * Delete a category.
     * ?force=1 unpublishes it from the shop first when it still holds inventory.
     * Inventory rows themselves are never deleted here — they belong to the inventory module.
     */
    public function destroy(Request $request, InventoryCategory $category): JsonResponse
    {
        $force = (bool) $request->input('force', false);
        $itemCount = $category->inventoryItems()->count();

        if ($itemCount > 0 && !$force) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'message' => 'Category still holds inventory items. Unpublish them first, or use ?force=1 to remove it from the shop.',
                    'code'    => 'CATEGORY_HAS_PRODUCTS',
                ],
            ], 400);
        }

        if ($force && $itemCount > 0) {
            // Only unpublish — leave the inventory records intact.
            $category->update(['for_shop' => false]);

            return response()->json(['success' => true, 'unpublished' => true], 200);
        }

        $category->delete();

        return response()->json(['success' => true], 204);
    }
}