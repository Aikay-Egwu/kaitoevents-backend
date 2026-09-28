<?php

namespace App\Http\Controllers;

// PublicShopController — anonymous storefront endpoints.
// Shop products are inventory items flagged `for_shop`; categories are inventory_categories
// flagged `for_shop`. Retired stock is never listed.
//   - Cache-Control: public, max-age=60 + ETag (xxhash128 of JSON payload) for 304s
//   - Products: paginated (9 default, max 30), category-slug filter, q search,
//     price band (min/max on shop_price), sort orders, eager images/options/approved reviews
//   - Product detail: eager + 4 related products (same category, exclude self)

use App\Http\Resources\Shop\ShopCategoryResource;
use App\Http\Resources\Shop\ShopProductResource;
use App\Models\Inventory;
use App\Models\InventoryCategory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PublicShopController extends Controller
{
    /** Relations the product resource needs — eager-loaded to avoid N+1. */
    private const PRODUCT_EAGER = ['category', 'images', 'customisationOptions', 'reviews'];

    // Attach Cache-Control and ETag response headers, serve 304 when If-None-Match hits.
    private function withCacheHeaders(JsonResponse $response, int $maxAge = 60): JsonResponse
    {
        $payload = $response->getContent();
        if ($payload !== false && $payload !== null && $payload !== '') {
            $etag = hash('xxh128', $payload);
            $response->header('ETag', '"' . $etag . '"');
            $response->header('Cache-Control', 'public, max-age=' . $maxAge . '');
            $response->header('Vary', 'Accept-Encoding');

            $ifNoneMatch = request()->header('If-None-Match');
            if ($ifNoneMatch !== null && trim((string)$ifNoneMatch) === '"' . $etag . '"') {
                $response->setStatusCode(304);
                $response->setContent('');
            }
        }
        return $response;
    }

    /**
     * GET /api/public/shop/categories — categories published to the shop.
     */
    public function categories(Request $request): JsonResponse
    {
        $categories = InventoryCategory::forShop()
            ->ordered()
            ->withCount('shopItems')
            ->get();

        $response = ShopCategoryResource::collection($categories)
            ->additional(['success' => true])
            ->toResponse($request);

        return $this->withCacheHeaders($response);
    }

    /**
     * GET /api/public/shop/categories/{slug} — category detail + up to 6 siblings.
     */
    public function categoryShow(Request $request, string $slug): JsonResponse
    {
        $category = InventoryCategory::forShop()
            ->where('slug', $slug)
            ->withCount('shopItems')
            ->firstOrFail();

        $related = InventoryCategory::forShop()
            ->where('id', '<>', $category->id)
            ->ordered()
            ->withCount('shopItems')
            ->limit(6)
            ->get();

        $response = response()->json([
            'success' => true,
            'data'    => (new ShopCategoryResource($category))->resolve(),
            'related' => ShopCategoryResource::collection($related)->resolve(),
        ]);

        return $this->withCacheHeaders($response);
    }

    /**
     * GET /api/public/shop/products — paginated, filterable listing.
     *  query: page, per_page (1..30), categories=slug1,slug2 (CSV), q (search),
     *         min_price_ex, max_price_ex (shop price ex VAT), sort = price_asc|price_desc|newest|name
     */
    public function products(Request $request): AnonymousResourceCollection
    {
        $perPage = (int) $request->input('per_page', 9);
        $perPage = max(1, min($perPage, 30));

        $builder = Inventory::with(self::PRODUCT_EAGER)
            ->forShop()
            ->active()
            ->where('condition', '!=', 'retired_written_off');

        if ($request->filled('categories')) {
            $slugs = array_values(array_filter(array_map('trim', explode(',', (string)$request->input('categories')))));
            if (count($slugs) > 0) {
                $builder->inCategorySlugs($slugs);
            }
        }
        if ($request->filled('q')) {
            $builder->search((string)$request->input('q'));
        }
        $min = $request->filled('min_price_ex') ? (float)$request->input('min_price_ex') : null;
        $max = $request->filled('max_price_ex') ? (float)$request->input('max_price_ex') : null;
        if ($min !== null || $max !== null) {
            $builder->shopPriceBetween($min, $max);
        }

        switch ($request->input('sort', 'newest')) {
            case 'price_asc':  $builder->orderBy('shop_price'); break;
            case 'price_desc': $builder->orderByDesc('shop_price'); break;
            case 'name':       $builder->orderBy('name'); break;
            case 'newest':
            default:           $builder->orderByDesc('id'); break;
        }

        return ShopProductResource::collection($builder->paginate($perPage))
            ->additional(['success' => true]);
    }

    /**
     * Wrap products collection with cache headers — GET /api/public/shop/products handler.
     */
    public function productsWithCache(Request $request): JsonResponse
    {
        $collection = $this->products($request);
        $response = $collection->toResponse($request);
        return $this->withCacheHeaders($response);
    }

    /**
     * GET /api/public/shop/products/{slug} — product detail + 4 related products.
     */
    public function productShow(Request $request, string $slug): JsonResponse
    {
        $product = Inventory::with(self::PRODUCT_EAGER)
            ->forShop()
            ->active()
            ->where('condition', '!=', 'retired_written_off')
            ->where('slug', $slug)
            ->firstOrFail();

        // Related: other shop-listed items in the same category, excluding this one.
        $relatedIds = [];
        if ($product->inventory_category_id !== null) {
            $relatedIds = Inventory::forShop()
                ->active()
                ->where('id', '<>', $product->id)
                ->where('inventory_category_id', $product->inventory_category_id)
                ->inRandomOrder()
                ->limit(4)
                ->pluck('id')
                ->all();
        }
        if (count($relatedIds) < 4) {
            $exclude = array_merge([$product->id], $relatedIds);
            $pad = Inventory::forShop()
                ->active()
                ->whereNotIn('id', $exclude)
                ->inRandomOrder()
                ->limit(4 - count($relatedIds))
                ->pluck('id')
                ->all();
            $relatedIds = array_merge($relatedIds, $pad);
        }

        $related = count($relatedIds) > 0
            ? Inventory::with(['category', 'images', 'reviews'])
                ->whereIn('id', $relatedIds)
                ->get()
                ->sortBy(function ($p) use ($relatedIds) {
                    return array_search($p->id, $relatedIds, true);
                })->values()
            : collect();

        $response = response()->json([
            'success' => true,
            'data'    => (new ShopProductResource($product))->resolve(),
            'related' => ShopProductResource::collection($related)->resolve(),
        ]);

        return $this->withCacheHeaders($response);
    }
}