<?php

namespace App\Contracts;

// PaymentResult DTO — immutable value object returned from PaymentProcessorInterface::createPayment().
// Contains only PCI-safe fields (provider payment id reference + metadata; never raw PAN/CVC).

final class PaymentResult
{
    public function __construct(
        public readonly bool   $success,
        public readonly string $provider,                 // 'simulated' | 'stripe'
        public readonly string $providerPaymentId,        // pi_xxx or internal ID
        public readonly string $status,                    // ShopPaymentTransaction::STATUS_*
        public readonly float  $amount,                    // captured or attempted amount (decimal 12,2)
        public readonly string $currency       = 'GBP',
        public readonly ?string $failureReason = null,
        public readonly array  $rawMetadata    = [],
    ) {}
}
