<?php

namespace App\Contracts;

use App\Models\ShopOrder;

// PaymentProcessorInterface — strategy boundary.
// Implementations:
//   - SimulatedPaymentProcessor (development / no-op testing success via env flag fail)
//   - StripePaymentProcessor    (production-ready stub that throws until STRIPE_SECRET installed)
interface PaymentProcessorInterface
{
    /**
     * Attempt to charge an order. The returned PaymentResult is safe to store
     * in shop_payment_transactions (no raw card data).
     *
     * @param  array<string, mixed>  $payload  Processor-specific fields (e.g. stripe payment_method_id).
     */
    public function createPayment(ShopOrder $order, array $payload = []): PaymentResult;

    /**
     * Provider short-name written to transaction.provider.
     */
    public function providerName(): string;
}
