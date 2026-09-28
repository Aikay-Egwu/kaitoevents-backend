<?php

namespace App\Services\Payments;

// StripePaymentProcessor — production stub. Throws RuntimeException if STRIPE_SECRET not set.
// In production, install "stripe/stripe-php" and replace createPayment with PaymentIntent logic.
// For PCI: this class should receive a client-generated payment_method_id, not raw PAN.

use App\Contracts\PaymentProcessorInterface;
use App\Contracts\PaymentResult;
use App\Models\ShopOrder;

final class StripePaymentProcessor implements PaymentProcessorInterface
{
    public function createPayment(ShopOrder $order, array $payload = []): PaymentResult
    {
        $secret = env('STRIPE_SECRET');
        if (!$secret || trim((string)$secret) === '') {
            throw new \RuntimeException(
                'Stripe payment processor is selected but STRIPE_SECRET environment variable is not set.'
            );
        }

        // TODO: integrate stripe/stripe-php PaymentIntent when SDK is installed.
        // Keep this stub so configuration binds correctly; runtime safe-guard throws instead of mishandling payments.
        throw new \RuntimeException(
            'StripePaymentProcessor stub: install stripe/stripe-php and implement PaymentIntent flow.'
        );
    }

    public function providerName(): string
    {
        return 'stripe';
    }
}
