<?php

namespace App\Http\Controllers;

// ShopProductController — admin management of inventory items published to the shop.
// Shop products ARE inventory rows (selected by the `for_shop` flag), so this controller
// operates on Inventory and exposes only shop-relevant fields.

use App\Http\Requests\Shop\StoreShopProductRequest;
use App\Http\Requests\Shop\UpdateShopProductRequest;
use App\Http\Resources\Shop\ShopProductResource;
use App\Models\Inventory;
use App\Models\ShopProductCustomisationOption;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ShopProductController extends Controller
{
    /** Relations the resource needs — eager-loaded to avoid N+1. */
    private const EAGER = ['category', 'images', 'customisationOptions', 'reviews'];

    /** Maps validated request keys onto inventory columns. */
    private const FIELD_MAP = [
        'name'                      => 'name',
        'slug'                      => 'slug',
        'tagline'                   => 'tagline',
        'description'               => 'description',
        'price'                     => 'price',
        'cost_price'                => 'cost_price',
        'suitable_for_guests_min'   => 'suitable_for_guests_min',
        'suitable_for_guests_max'   => 'suitable_for_guests_max',
        'suitable_for_guests_label' => 'suitable_for_guests_label',
        'is_active'                 => 'is_active',
        'inventory_category_id'     => 'inventory_category_id',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min((int) $request->input('per_page', 9), 100);
        $builder = Inventory::with(self::EAGER)->forShop();

        if ($request->filled('q')) {
            $builder->search((string) $request->input('q'));
        }
        if ($request->filled('category_ids')) {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string) $request->input('category_ids')))));
            if (count($ids) > 0) {
                $builder->whereIn('inventory_category_id', $ids);
            }
        }
        if ($request->filled('categories')) {
            $slugs = array_values(array_filter(array_map('trim', explode(',', (string) $request->input('categories')))));
            if (count($slugs) > 0) {
                $builder->inCategorySlugs($slugs);
            }
        }
        if ($request->filled('is_active')) {
            $builder->where('is_active', (bool) $request->input('is_active'));
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

    public function store(StoreShopProductRequest $request): JsonResponse
    {
        $valid = $request->validated();
        $customisations = $valid['customisation_options'] ?? [];
        unset($valid['customisation_options'], $valid['category_ids']);

        $shopPrice = (float) $valid['price_ex_vat'];

        $payload = ['for_shop' => true, 'shop_price' => $shopPrice];
        foreach (self::FIELD_MAP as $input => $column) {
            if (array_key_exists($input, $valid)) {
                $payload[$column] = $valid[$input];
            }
        }
        // Inventory requires an internal hire price and a cost; mirror the shop price
        // unless the caller supplied them explicitly.
        $payload['price'] = (float) ($valid['price'] ?? $shopPrice);
        $payload['cost_price'] = (float) ($valid['cost_price'] ?? 0);
        $payload['is_active'] = (bool) ($valid['is_active'] ?? true);

        $item = Inventory::create($payload);

        foreach ($customisations as $i => $opt) {
            ShopProductCustomisationOption::create([
                'inventory_id' => $item->id,
                'option_key'   => $opt['option_key'],
                'label'        => $opt['label'],
                'option_type'  => $opt['option_type'],
                'is_required'  => (bool) ($opt['is_required'] ?? true),
                'sort_order'   => (int) ($opt['sort_order'] ?? $i + 1),
                'choices'      => $opt['choices'],
            ]);
        }

        return (new ShopProductResource($item->load(self::EAGER)))
            ->additional(['success' => true])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Inventory $product): JsonResponse
    {
        return (new ShopProductResource($product->load(self::EAGER)))
            ->additional(['success' => true])
            ->response();
    }

    public function update(UpdateShopProductRequest $request, Inventory $product): JsonResponse
    {
        $valid = $request->validated();
        unset($valid['category_ids']);

        $payload = [];
        foreach (self::FIELD_MAP as $input => $column) {
            if (array_key_exists($input, $valid)) {
                $payload[$column] = $valid[$input];
            }
        }
        if (array_key_exists('price_ex_vat', $valid)) {
            $payload['shop_price'] = (float) $valid['price_ex_vat'];
        }

        $product->update($payload);

        return (new ShopProductResource($product->load(self::EAGER)))
            ->additional(['success' => true])
            ->response();
    }

    public function destroy(Inventory $product): JsonResponse
    {
        // Inventory::booted() removes gallery files; shop images/options/reviews cascade.
        $product->delete();

        return response()->json(['success' => true], 204);
    }
}