<?php

namespace App\Http\Controllers;

use App\Http\Requests\Client\AddBasketItemRequest;
use App\Http\Requests\Client\UpdateBasketItemRequest;
use App\Http\Resources\Client\ClientBasketResource;
use App\Models\Inventory;
use App\Models\ShopClientBasket;
use App\Models\ShopClientBasketItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientBasketController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $basket = ShopClientBasket::getOrCreateFor($request->user());
        $basket->load('items.inventory.images');

        return (new ClientBasketResource($basket))
            ->additional(['success' => true])
            ->response();
    }

    public function addItem(AddBasketItemRequest $request): JsonResponse
    {
        $data = $request->validated();
        $client = $request->user();

        $item = Inventory::with('customisationOptions')
            ->where('id', $data['inventory_id'])
            ->where('for_shop', true)
            ->where('is_active', true)
            ->firstOrFail();

        $basket = ShopClientBasket::getOrCreateFor($client);
        $selectedOptions = $data['selected_options'] ?? null;

        $lineKey = ShopClientBasketItem::buildLineKey($item->id, $selectedOptions);
        $variantLabel = $this->buildVariantLabel($item, $selectedOptions);
        $unitPrice = round((float) $item->shop_price, 2);

        $existing = $basket->items()->where('line_key', $lineKey)->first();

        if ($existing) {
            $newQty = min(99, $existing->quantity + $data['quantity']);
            $existing->update(['quantity' => $newQty]);
        } else {
            $basket->items()->create([
                'inventory_id' => $item->id,
                'quantity' => $data['quantity'],
                'selected_options' => $selectedOptions,
                'unit_price_ex_vat' => $unitPrice,
                'variant_label' => $variantLabel,
                'line_key' => $lineKey,
            ]);
        }

        $basket->load('items.inventory.images');

        return (new ClientBasketResource($basket))
            ->additional(['success' => true])
            ->response()
            ->setStatusCode(201);
    }

    public function updateItem(Request $request, ShopClientBasketItem $item): JsonResponse
    {
        $basket = ShopClientBasket::getOrCreateFor($request->user());

        if ($item->shop_client_basket_id !== $basket->id) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'FORBIDDEN', 'message' => 'Item does not belong to your basket.'],
            ], 403);
        }

        $validated = $request->validate(['quantity' => 'required|integer|min:1|max:99']);
        $item->update(['quantity' => $validated['quantity']]);

        $basket->load('items.inventory.images');

        return (new ClientBasketResource($basket))
            ->additional(['success' => true])
            ->response();
    }

    public function removeItem(Request $request, ShopClientBasketItem $item): JsonResponse
    {
        $basket = ShopClientBasket::getOrCreateFor($request->user());

        if ($item->shop_client_basket_id !== $basket->id) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'FORBIDDEN', 'message' => 'Item does not belong to your basket.'],
            ], 403);
        }

        $item->delete();
        $basket->load('items.inventory.images');

        return (new ClientBasketResource($basket))
            ->additional(['success' => true])
            ->response();
    }

    public function clear(Request $request): JsonResponse
    {
        $basket = ShopClientBasket::getOrCreateFor($request->user());
        $basket->items()->delete();

        return response()->json(['success' => true, 'message' => 'Basket cleared.']);
    }

    private function buildVariantLabel(Inventory $item, ?array $selectedOptions): ?string
    {
        if (empty($selectedOptions)) {
            return null;
        }

        $labels = [];
        $optionMap = $item->customisationOptions->keyBy('option_key');

        foreach ($selectedOptions as $key => $value) {
            $option = $optionMap[$key] ?? null;
            if (!$option) {
                $labels[] = $value;
                continue;
            }

            $choices = $option->choices ?? [];
            $matched = collect($choices)->firstWhere('value', $value);
            $labels[] = $matched['label'] ?? $value;
        }

        return $labels ? implode(', ', $labels) : null;
    }
}
