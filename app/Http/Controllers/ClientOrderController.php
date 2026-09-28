<?php

namespace App\Http\Controllers;

use App\Http\Requests\Client\PlaceClientOrderRequest;
use App\Http\Resources\Shop\ShopOrderResource;
use App\Models\Inventory;
use App\Models\ShopClientBasket;
use App\Models\ShopOrder;
use App\Models\ShopOrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientOrderController extends Controller
{
    public function store(PlaceClientOrderRequest $request): JsonResponse
    {
        $data = $request->validated();
        $client = $request->user();

        $basket = ShopClientBasket::open()
            ->where('client_id', $client->id)
            ->with(['items.inventory.customisationOptions'])
            ->first();

        if (!$basket || $basket->items->isEmpty()) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'EMPTY_BASKET', 'message' => 'Your basket is empty.'],
            ], 422);
        }

        $order = DB::transaction(function () use ($data, $client, $basket) {
            $ref = ShopOrder::generateReference();

            $itemsForTotals = $basket->items->map(fn ($item) => [
                'unit_price_ex_vat' => (float) $item->unit_price_ex_vat,
                'quantity' => $item->quantity,
            ])->toArray();

            $totals = ShopClientBasket::getOrCreateFor($client)->computeTotals();
            $shipping = $totals['shipping_amount'];
            $recomputed = ShopOrder::recomputeTotalsFromItems($itemsForTotals, $shipping);

            $order = ShopOrder::create([
                'client_id' => $client->id,
                'order_ref' => $ref,
                'status' => ShopOrder::initialStatusFor($data['payment_method']),
                'customer_full_name' => $data['customer_full_name'],
                'customer_email' => $data['customer_email'],
                'customer_phone' => $data['customer_phone'],
                'billing_address_line_1' => $data['billing_address_line_1'],
                'billing_address_line_2' => $data['billing_address_line_2'] ?? null,
                'billing_city' => $data['billing_city'],
                'billing_postcode' => $data['billing_postcode'],
                'billing_country' => $data['billing_country'],
                'event_date' => $data['event_date'] ?? null,
                'event_venue' => $data['event_venue'] ?? null,
                'subtotal_ex_vat' => $recomputed['subtotal_ex_vat'],
                'vat_amount' => $recomputed['vat_amount'],
                'shipping_amount' => $shipping,
                'grand_total' => $recomputed['grand_total'],
                'payment_method' => $data['payment_method'],
                'notes' => $data['notes'] ?? null,
                'source_ref' => 'shop-client',
            ]);

            foreach ($basket->items as $item) {
                $inventory = $item->inventory;
                $customisations = null;

                if (!empty($item->selected_options) && $inventory) {
                    $optMap = $inventory->customisationOptions->keyBy('option_key');
                    $customisations = [];

                    foreach ($item->selected_options as $key => $value) {
                        $option = $optMap[$key] ?? null;
                        $deltaInc = null;
                        $label = $value;

                        if ($option) {
                            foreach (($option->choices_with_vat ?? []) as $ch) {
                                if (($ch['value'] ?? null) === $value) {
                                    $label = $ch['label'] ?? $value;
                                    $deltaInc = (float) ($ch['price_delta_inc_vat_raw'] ?? 0);
                                    break;
                                }
                            }
                        }

                        $customisations[] = [
                            'option_key' => $key,
                            'value' => $value,
                            'label' => $label,
                            'price_delta_inc_vat' => $deltaInc !== null ? round($deltaInc, 2) : null,
                        ];
                    }
                }

                $unitEx = round((float) $item->unit_price_ex_vat, 2);
                $qty = (int) $item->quantity;
                $lineEx = round($unitEx * $qty, 2);
                $variantLabel = $customisations
                    ? implode(', ', array_map(fn ($c) => $c['label'], $customisations))
                    : $item->variant_label;

                ShopOrderItem::create([
                    'shop_order_id' => $order->id,
                    'inventory_id' => $inventory?->id,
                    'snapshot_name' => $inventory?->name ?? 'Unknown item',
                    'snapshot_slug' => $inventory?->slug ?? '',
                    'snapshot_variant_label' => $variantLabel,
                    'quantity' => $qty,
                    'unit_price_ex_vat' => $unitEx,
                    'line_price_ex_vat' => $lineEx,
                    'customisations' => $customisations ?: null,
                ]);
            }

            $basket->update(['status' => ShopClientBasket::STATUS_CONVERTED]);

            return $order;
        });

        $expires = null;
        if ($order->status === ShopOrder::STATUS_AWAITING_PAYMENT) {
            $expires = now()->addMinutes(30)->toIso8601String();
        }

        return (new ShopOrderResource($order->load(['items', 'paymentTransactions'])))
            ->additional([
                'success' => true,
                'expires_at' => $expires,
            ])
            ->response()
            ->setStatusCode(201);
    }

    public function index(Request $request): JsonResponse
    {
        $orders = ShopOrder::with('items')
            ->where('client_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate(10);

        return response()->json([
            'success' => true,
            'data' => ShopOrderResource::collection($orders),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }
}
