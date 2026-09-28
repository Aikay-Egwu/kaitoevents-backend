<?php

namespace App\Services\Payments;

// SimulatedPaymentProcessor — always succeeds unless env SHOP_SIMULATE_PAYMENT_FAIL=true.
// Used when SHOP_PAYMENT_PROCESSOR=simulated (default) for local/dev; no real card data needed.

use App\Contracts\PaymentProcessorInterface;
use App\Contracts\PaymentResult;
use App\Models\ShopOrder;
use Illuminate\Support\Str;

final class SimulatedPaymentProcessor implements PaymentProcessorInterface
{
    public function createPayment(ShopOrder $order, array $payload = []): PaymentResult
    {
        $forceFail = filter_var(env('SHOP_SIMULATE_PAYMENT_FAIL', false), FILTER_VALIDATE_BOOLEAN);
        $amount    = round((float)$order->grand_total, 2);
        $pi        = 'pi_sim_' . strtolower(Str::random(24));

        if ($forceFail) {
            return new PaymentResult(
                success: false,
                provider: $this->providerName(),
                providerPaymentId: $pi,
                status: \App\Models\ShopPaymentTransaction::STATUS_FAILED,
                amount: $amount,
                currency: 'GBP',
                failureReason: 'Simulated payment declined (SHOP_SIMULATE_PAYMENT_FAIL=true).',
                rawMetadata: ['simulated' => true, 'declined' => true] + $payload,
            );
        }

        return new PaymentResult(
            success: true,
            provider: $this->providerName(),
            providerPaymentId: $pi,
            status: \App\Models\ShopPaymentTransaction::STATUS_SUCCEEDED,
            amount: $amount,
            currency: 'GBP',
            failureReason: null,
            rawMetadata: ['simulated' => true] + $payload,
        );
    }

    public function providerName(): string
    {
        return 'simulated';
    }
}
