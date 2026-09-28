<?php

namespace App\Http\Controllers;

// PublicShopPaymentController — public (and provider-signed) payment endpoints.
//   POST /orders/{ref}/payments  — creates transaction record, calls processor, updates order.
//   POST /payments/webhook       — provider webhook endpoint; requires x-shop-provider header;
//                                   Stripe additionally verifies STRIPE_WEBHOOK_SECRET HMAC.

use App\Contracts\PaymentProcessorInterface;
use App\Http\Resources\Shop\ShopPaymentTransactionResource;
use App\Models\ShopOrder;
use App\Models\ShopPaymentTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublicShopPaymentController extends Controller
{
    public function __construct(
        protected PaymentProcessorInterface $processor,
    ) {}

    /**
     * POST /api/public/shop/orders/{ref}/payments
     *   Creates DB transaction UUID ref, runs processor, updates order status.
     */
    public function storeForOrder(Request $request, string $ref): JsonResponse
    {
        $payload = $request->validate([
            'payment_method_id'  => ['nullable', 'string', 'max:255'],
            'return_url'         => ['nullable', 'url', 'max:500'],
            'customer_payload'   => ['nullable', 'array'],
        ]);

        $order = ShopOrder::where('order_ref', $ref)
            ->whereIn('status', [ShopOrder::STATUS_AWAITING_PAYMENT, ShopOrder::STATUS_PENDING])
            ->first();
        if (!$order) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'ORDER_NOT_FOUND', 'message' => 'Order not payable.'],
            ], 404);
        }

        $result = DB::transaction(function () use ($order, $payload) {
            // Allocate unique UUID-like transaction_ref (TX-UUID4).
            for ($tries = 0; $tries < 5; $tries++) {
                $txRef = 'TX-' . strtoupper(Str::random(8)) . '-' . strtoupper(Str::random(8));
                if (!ShopPaymentTransaction::where('transaction_ref', $txRef)->exists()) {
                    break;
                }
            }

            $txRow = ShopPaymentTransaction::create([
                'shop_order_id'      => $order->id,
                'transaction_ref'    => $txRef,
                'provider'           => $this->processor->providerName(),
                'provider_payment_id'=> '',   // Filled after processor call.
                'status'             => ShopPaymentTransaction::STATUS_CREATED,
                'amount'             => round((float)$order->grand_total, 2),
                'currency'           => 'GBP',
                'raw_metadata'       => ['attempted_at' => now()->toIso8601String(), 'payload' => $payload],
                'processed_at'       => null,
            ]);

            $result = $this->processor->createPayment($order, $payload);

            // Update tx with provider result.
            $txRow->update([
                'provider_payment_id' => $result->providerPaymentId,
                'status'              => $result->status,
                'failure_reason'      => $result->failureReason,
                'raw_metadata'        => array_merge((array)$txRow->raw_metadata, $result->rawMetadata),
                'processed_at'        => Carbon::now(),
            ]);

            // Update order status based on processor success/failure.
            if ($result->success && $result->status === ShopPaymentTransaction::STATUS_SUCCEEDED) {
                $order->update(['status' => ShopOrder::STATUS_PAID]);
            } elseif ($result->status === ShopPaymentTransaction::STATUS_FAILED) {
                // Keep awaiting_payment (allow retry) and append failure log to notes.
                $note = "[Payment failed {$txRef}] " . ($result->failureReason ?? 'Unknown reason');
                $order->update([
                    'notes' => trim(($order->notes ? ($order->notes . "\n\n") : '') . $note) ?: null,
                ]);
            }

            return [$txRow->fresh(), $result];
        });

        [$txRow, $processorResult] = $result;

        return (new ShopPaymentTransactionResource($txRow))
            ->additional([
                'success'          => $processorResult->success,
                'order_status'     => $order->fresh()->status,
                'processor_status' => $processorResult->status,
            ])
            ->response()
            ->setStatusCode($processorResult->success ? 201 : 402);
    }

    /**
     * POST /api/public/shop/payments/webhook — provider webhook sink.
     *   Requires x-shop-provider: simulated|stripe header.
     *   For stripe: verifies x-shop-webhook-signature HMAC (SHA256 hex of body with STRIPE_WEBHOOK_SECRET).
     */
    public function webhook(Request $request): JsonResponse
    {
        $provider = (string)$request->header('x-shop-provider', '');
        if (!in_array($provider, ['simulated', 'stripe'], true)) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'INVALID_PROVIDER', 'message' => 'Missing or invalid x-shop-provider header.'],
            ], 400);
        }

        $bodyRaw = $request->getContent();
        $body    = json_decode($bodyRaw, true);
        if (!is_array($body)) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'INVALID_JSON', 'message' => 'Webhook body is not valid JSON.'],
            ], 400);
        }

        if ($provider === 'stripe') {
            $secret = env('STRIPE_WEBHOOK_SECRET') ?: (($_ENV['STRIPE_WEBHOOK_SECRET'] ?? null) ?: (getenv('STRIPE_WEBHOOK_SECRET') ?: null));
            if ($secret && trim((string)$secret) !== '') {
                $sig = (string)$request->header('x-shop-webhook-signature', '');
                $expected = hash_hmac('sha256', $bodyRaw, (string)$secret);
                if (!hash_equals($expected, strtolower($sig))) {
                    return response()->json([
                        'success' => false,
                        'error'   => ['code' => 'BAD_SIGNATURE', 'message' => 'Stripe webhook signature did not match.'],
                    ], 401);
                }
            }
        }

        // Persist raw_metadata row keyed by order_ref if caller included one; otherwise drop.
        $orderRef = (string)($body['order_ref'] ?? ($body['data']['object']['order_ref'] ?? ''));
        if ($orderRef !== '') {
            $order = ShopOrder::where('order_ref', $orderRef)->first();
            if ($order) {
                ShopPaymentTransaction::create([
                    'shop_order_id'      => $order->id,
                    'transaction_ref'    => 'WH-' . strtoupper(Str::random(8)) . '-' . strtoupper(Str::random(6)),
                    'provider'           => $provider,
                    'provider_payment_id'=> (string)($body['data']['object']['id'] ?? $body['provider_payment_id'] ?? 'webhook'),
                    'status'             => ShopPaymentTransaction::STATUS_PENDING,
                    'amount'             => round((float)($body['data']['object']['amount'] ?? $body['amount'] ?? $order->grand_total), 2),
                    'currency'           => (string)($body['data']['object']['currency'] ?? $body['currency'] ?? 'GBP'),
                    'raw_metadata'       => $body,
                    'processed_at'       => Carbon::now(),
                ]);
            }
        }

        return response()->json(['success' => true, 'provider' => $provider, 'received' => true], 200);
    }
}
