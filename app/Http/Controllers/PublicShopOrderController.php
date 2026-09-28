<?php

namespace App\Http\Controllers;

// PublicShopOrderController — public POST order creation + lookup by ref+email.
// Also mounts payment endpoints (POST order/{ref}/payments) used by T7.

use App\Http\Requests\Shop\CreateShopOrderRequest;
use App\Http\Resources\Shop\ShopOrderResource;
use App\Models\Inventory;
use App\Models\ShopOrder;
use App\Models\ShopOrderItem;
use App\Models\ShopProductCustomisationOption;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublicShopOrderController extends Controller
{
    /**
     * POST /api/public/shop/orders — create order.
     * Integrity check runs in CreateShopOrderRequest (TOTAL_MISMATCH, product existence, prices match DB).
     */
    public function store(CreateShopOrderRequest $request): JsonResponse
    {
        $data = $request->validated();

        $order = DB::transaction(function () use ($data) {
            // Ref generation is a DB-unique guarantee so wrap in TX and retry 5x.
            $ref = null;
            for ($tries = 0; $tries < 5; $tries++) {
                $candidate = ShopOrder::generateReference();
                if (!ShopOrder::where('order_ref', $candidate)->exists()) {
                    $ref = $candidate;
                    break;
                }
            }
            if ($ref === null) {
                throw new \RuntimeException('Failed to allocate unique order reference');
            }

            $totals = ShopOrder::recomputeTotalsFromItems($data['items'], (float)$data['shipping_amount']);

            $order = ShopOrder::create([
                'order_ref'             => $ref,
                'status'                => ShopOrder::initialStatusFor($data['payment_method']),
                'customer_full_name'    => $data['customer_full_name'],
                'customer_email'        => $data['customer_email'],
                'customer_phone'        => $data['customer_phone'],
                'billing_address_line_1'=> $data['billing_address_line_1'],
                'billing_address_line_2'=> $data['billing_address_line_2'] ?? null,
                'billing_city'          => $data['billing_city'],
                'billing_postcode'      => $data['billing_postcode'],
                'billing_country'       => $data['billing_country'],
                'event_date'            => $data['event_date'] ?? null,
                'event_venue'           => $data['event_venue'] ?? null,
                'subtotal_ex_vat'       => $totals['subtotal_ex_vat'],
                'vat_amount'            => $totals['vat_amount'],
                'shipping_amount'       => round((float)$data['shipping_amount'], 2),
                'grand_total'           => $totals['grand_total'],
                'payment_method'        => $data['payment_method'],
                'notes'                 => $data['notes'] ?? null,
                'source_ref'            => $data['source_ref'] ?? null,
            ]);

            // Build snapshot line items (denormalised) with customisations JSON.
            $productIds = array_values(array_unique(array_map(
                fn (array $l) => (int)$l['inventory_id'],
                $data['items']
            )));
            $products = Inventory::with('customisationOptions')
                ->whereIn('id', $productIds)
                ->get()
                ->keyBy('id');

            foreach ($data['items'] as $i => $line) {
                /** @var Inventory $p */
                $p = $products[(int)$line['inventory_id']];

                $customisations = [];
                if (!empty($line['customisations']) && is_array($line['customisations'])) {
                    $optMap = $p->customisationOptions->keyBy('option_key');
                    foreach ($line['customisations'] as $c) {
                        $key   = (string)($c['option_key'] ?? '');
                        $value = (string)($c['value'] ?? '');
                        $label = isset($c['label']) && trim((string)$c['label']) !== ''
                            ? (string)$c['label']
                            : $value;
                        $deltaInc = isset($c['price_delta_inc_vat']) ? (float)$c['price_delta_inc_vat'] : null;
                        if ($deltaInc === null && isset($optMap[$key])) {
                            /** @var ShopProductCustomisationOption $opt */
                            $opt = $optMap[$key];
                            foreach (($opt->choices_with_vat ?? []) as $ch) {
                                if (($ch['value'] ?? null) === $value) {
                                    $deltaInc = (float)($ch['price_delta_inc_vat_raw'] ?? 0);
                                    break;
                                }
                            }
                        }
                        $customisations[] = [
                            'option_key'           => $key,
                            'value'                => $value,
                            'label'                => $label,
                            'price_delta_inc_vat'  => $deltaInc !== null ? round($deltaInc, 2) : null,
                        ];
                    }
                }

                $unitEx  = round((float)$line['unit_price_ex_vat'], 2);
                $qty     = (int)$line['quantity'];
                $lineEx  = round($unitEx * $qty, 2);

                $variantLabel = $customisations ? implode(', ', array_map(fn($c) => $c['label'], $customisations)) : null;

                ShopOrderItem::create([
                    'shop_order_id'           => $order->id,
                    'inventory_id'            => $p->id,
                    'snapshot_name'           => $p->name,
                    'snapshot_slug'           => $p->slug,
                    'snapshot_variant_label'  => $variantLabel,
                    'quantity'                => $qty,
                    'unit_price_ex_vat'       => $unitEx,
                    'line_price_ex_vat'       => $lineEx,
                    'customisations'          => count($customisations) > 0 ? $customisations : null,
                ]);
            }

            return $order;
        });

        $expires = null;
        if ($order->status === ShopOrder::STATUS_AWAITING_PAYMENT) {
            $expires = Carbon::now()->addMinutes(30)->toIso8601String();
        }

        return (new ShopOrderResource($order->load(['items', 'paymentTransactions'])))
            ->additional([
                'success'     => true,
                'expires_at'  => $expires,
            ])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/public/shop/orders/{ref}?email=… — ref+email lookup (R-8: 404 on mismatch, no enumeration).
     */
    public function show(Request $request, string $ref): JsonResponse
    {
        $email = (string)$request->query('email', '');
        if ($email === '') {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'EMAIL_REQUIRED', 'message' => 'The email query parameter is required.'],
            ], 400);
        }

        $order = ShopOrder::with(['items', 'paymentTransactions'])
            ->where('order_ref', $ref)
            ->first();

        if ($order === null) {
            abort(404);
        }
        // Case-insensitive email match prevents enumeration (same 404 as no ref).
        if (strcasecmp($order->customer_email, $email) !== 0) {
            abort(404);
        }

        return (new ShopOrderResource($order))
            ->additional(['success' => true])
            ->response();
    }
}
